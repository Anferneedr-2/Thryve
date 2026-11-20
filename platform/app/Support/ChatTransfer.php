<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Moves one chatbot's conversations from one installation to another through a single file.
 *
 * The file holds the conversation text and the attached files, so it is private: it lives in storage/app/transfer, which
 * git ignores, and is copied across by hand. It never holds passwords, access keys, usage records or user ids: people
 * are matched by username, and a person who does not exist on the receiving side is skipped and reported (never created).
 * Importing is safe to repeat: a conversation that is already there is recognised and left alone.
 */
final class ChatTransfer
{
    public const FORMAT = 'thryve-chat-transfer';

    /** @return array<string, mixed> the bundle */
    public static function export(string $agentSlug, array $usernames): array
    {
        $agent = Agent::where('slug', $agentSlug)->first();
        if (! $agent) {
            throw new InvalidArgumentException("There is no chatbot with the name \"{$agentSlug}\" here.");
        }
        $users = User::whereIn('username', $usernames)->pluck('username', 'id');
        $disk = Storage::disk(config('thryve.private_disk'));
        $conversations = [];
        $files = 0;

        foreach (Conversation::where('agent_id', $agent->id)->whereNull('deleted_at')->whereIn('user_id', $users->keys())->orderBy('id')->get() as $c) {
            $messages = [];
            foreach (Message::where('conversation_id', $c->id)->orderBy('id')->get() as $m) {
                $attachments = [];
                foreach (Attachment::where('message_id', $m->id)->orderBy('id')->get() as $a) {
                    $raw = $a->storage_key && $disk->exists($a->storage_key) ? $disk->get($a->storage_key) : null;
                    $files += $raw !== null ? 1 : 0;
                    $attachments[] = [
                        'original_name' => $a->original_name, 'kind' => $a->kind, 'size' => (int) $a->size, 'sha256' => $a->sha256,
                        'extracted_text' => (string) $a->extracted_text, 'truncated' => (bool) $a->truncated, 'generated' => (bool) $a->generated,
                        'created_at' => self::iso($a->created_at), 'file_base64' => $raw !== null ? base64_encode($raw) : null,
                    ];
                }
                $messages[] = [
                    'role' => $m->role, 'content' => $m->content, 'status' => $m->status, 'rating' => $m->rating,
                    'superseded_at' => self::iso($m->superseded_at), 'created_at' => self::iso($m->created_at), 'updated_at' => self::iso($m->updated_at),
                    'attachments' => $attachments,
                ];
            }
            $conversations[] = [
                'user' => $users[$c->user_id], 'title' => $c->title, 'archived_at' => self::iso($c->archived_at),
                'last_message_at' => self::iso($c->last_message_at), 'created_at' => self::iso($c->created_at), 'updated_at' => self::iso($c->updated_at),
                'messages' => $messages,
            ];
        }

        return [
            'format' => self::FORMAT, 'version' => 1, 'exported_at' => now()->toIso8601String(),
            // The chatbot's public description only. Its access keys never leave this machine.
            'agent' => ['slug' => $agent->slug, 'name' => $agent->name, 'description' => $agent->description, 'base_url' => $agent->base_url, 'profile' => $agent->profile],
            'counts' => ['conversations' => count($conversations), 'messages' => array_sum(array_map(fn ($c) => count($c['messages']), $conversations)), 'files' => $files],
            'conversations' => $conversations,
        ];
    }

    /**
     * @return array{agent: string, imported: int, messages: int, files: int, duplicates: int, missing_users: array<string, int>, missing_files: int, dry_run: bool}
     */
    public static function import(array $bundle, bool $dryRun = false): array
    {
        if (($bundle['format'] ?? null) !== self::FORMAT || ($bundle['version'] ?? null) !== 1 || ! is_array($bundle['conversations'] ?? null) || empty($bundle['agent']['slug'])) {
            throw new InvalidArgumentException('This is not a chat transfer file made by Thryve.');
        }
        $report = ['agent' => 'found', 'imported' => 0, 'messages' => 0, 'files' => 0, 'duplicates' => 0, 'missing_users' => [], 'missing_files' => 0, 'dry_run' => $dryRun];
        $users = User::pluck('id', 'username');

        // The chatbot is only made when there is at least one conversation to put in it, so a machine where nobody in the file
        // exists yet (a fresh install running its migrations) is left exactly as it was.
        $agent = Agent::active()->where('slug', $bundle['agent']['slug'])->first();
        // A chatbot that was removed here keeps its name, so a new one cannot be made under it: bring that one back instead.
        $removed = $agent ? null : Agent::whereNotNull('removed_at')->where('slug', $bundle['agent']['slug'])->first();
        if (! $agent) {
            $report['agent'] = 'not here, and nothing to import yet so none was made';
        }

        foreach ($bundle['conversations'] as $c) {
            $userId = $users[$c['user']] ?? null;
            if (! $userId) {
                $report['missing_users'][$c['user']] = ($report['missing_users'][$c['user']] ?? 0) + 1;

                continue;
            }
            if (($agent ?? $removed) && self::exists(($agent ?? $removed)->id, $userId, $c)) {
                $report['duplicates']++;

                continue;
            }
            $report['imported']++;
            $report['messages'] += count($c['messages']);
            foreach ($c['messages'] as $m) {
                foreach ($m['attachments'] ?? [] as $a) {
                    isset($a['file_base64']) ? $report['files']++ : $report['missing_files']++;
                }
            }
            if ($dryRun) {
                continue;
            }
            if (! $agent && $removed) {
                // Its settings stay as they were here; its keys were wiped when it was removed, so it still needs a key.
                $removed->update(['removed_at' => null, 'is_enabled' => true]);
                $agent = $removed;
                $report['agent'] = 'brought back (it had been removed here; it has no access key until you create or import one)';
            } elseif (! $agent) {
                // No keys are copied. The chatbot exists so the chats have somewhere to live; it cannot answer until keys are made here.
                $a = $bundle['agent'];
                try {
                    $agent = Agent::create(['name' => $a['name'], 'slug' => $a['slug'], 'base_url' => $a['base_url'], 'profile' => $a['profile'] ?? null, 'description' => $a['description'] ?? null, 'is_default' => false, 'is_enabled' => true]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    throw new InvalidArgumentException("A chatbot named \"{$a['slug']}\" already exists here and could not be used. Check the Agents page, or ask for help.");
                }
                $report['agent'] = 'created (no access key yet: open Agents to create its keys)';
            }
            DB::transaction(fn () => self::insert($agent->id, $userId, $c));
        }
        if ($dryRun && ! $agent && $report['imported'] > 0) {
            $report['agent'] = $removed
                ? 'would be brought back (it had been removed here; it would have no access key until you create or import one)'
                : 'would be created (no access key yet: open Agents to create its keys)';
        }

        return $report;
    }

    /** The same person, chatbot, title, start time and first message already exist here. */
    private static function exists(int $agentId, int $userId, array $c): bool
    {
        $same = DB::table('conversations')->where('agent_id', $agentId)->where('user_id', $userId)->where('title', $c['title'])->where('created_at', self::db($c['created_at']))->pluck('id');
        foreach ($same as $id) {
            $first = DB::table('messages')->where('conversation_id', $id)->orderBy('id')->value('content');
            if ($first === ($c['messages'][0]['content'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function insert(int $agentId, int $userId, array $c): void
    {
        $disk = Storage::disk(config('thryve.private_disk'));
        $conversationId = DB::table('conversations')->insertGetId([
            'user_id' => $userId, 'agent_id' => $agentId, 'title' => $c['title'], 'archived_at' => self::db($c['archived_at'] ?? null),
            'last_message_at' => self::db($c['last_message_at'] ?? null), 'created_at' => self::db($c['created_at']), 'updated_at' => self::db($c['updated_at'] ?? $c['created_at']),
        ]);
        foreach ($c['messages'] as $m) {
            $messageId = DB::table('messages')->insertGetId([
                'conversation_id' => $conversationId, 'role' => $m['role'], 'content' => $m['content'], 'status' => $m['status'] ?? 'complete', 'rating' => $m['rating'] ?? null,
                'superseded_at' => self::db($m['superseded_at'] ?? null), 'created_at' => self::db($m['created_at']), 'updated_at' => self::db($m['updated_at'] ?? $m['created_at']),
            ]);
            foreach ($m['attachments'] ?? [] as $a) {
                if (empty($a['file_base64'])) {
                    continue; // the file was missing where it was exported; there is nothing to bring across
                }
                $raw = base64_decode($a['file_base64'], true);
                if ($raw === false || hash('sha256', $raw) !== $a['sha256']) {
                    throw new InvalidArgumentException("The attached file \"{$a['original_name']}\" is damaged in the transfer file.");
                }
                $key = 'attachments/'.Str::random(40).'.bin';
                $disk->put($key, $raw);
                DB::table('attachments')->insert([
                    'user_id' => $userId, 'conversation_id' => $conversationId, 'message_id' => $messageId, 'original_name' => $a['original_name'], 'kind' => $a['kind'],
                    'size' => $a['size'], 'storage_key' => $key, 'sha256' => $a['sha256'], 'extracted_text' => $a['extracted_text'] ?? '', 'truncated' => (int) ($a['truncated'] ?? false),
                    'generated' => (int) ($a['generated'] ?? false), 'created_at' => self::db($a['created_at'] ?? $m['created_at']), 'updated_at' => self::db($a['created_at'] ?? $m['created_at']),
                ]);
            }
        }
    }

    private static function iso($value): ?string
    {
        return $value?->toIso8601String();
    }

    /** ISO time from the file to the database's own format (UTC), or null. */
    private static function db(?string $iso): ?string
    {
        return $iso ? \Carbon\CarbonImmutable::parse($iso)->utc()->format('Y-m-d H:i:s') : null;
    }
}
