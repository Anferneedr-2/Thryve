<?php

namespace App\Ai;

use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * One chat turn: save what the person wrote, check the allowance, ask the conversation's agent (through the ledger so
 * limits and usage apply), save the answer. Every attempt (send, edit, regenerate, retry) is a separate usage request.
 */
class ChatService
{
    /** Roughly how much earlier conversation is sent along (characters, newest kept). Placeholder until D08. */
    public const CONTEXT_CHARS = 24000;

    public function __construct(private AgentRunner $runner, private HermesClient $hermes, private Allowance $allowance, private AgentService $agents) {}

    /** @return list<string> labels of sensitive data found, for the warn/block policy */
    public function sensitive(string $text): array
    {
        return SensitiveData::find($text);
    }

    public function start(User $user, ?Agent $agent, string $text): Conversation
    {
        return Conversation::create(['user_id' => $user->id, 'agent_id' => $agent?->id, 'title' => $this->titleFrom($text)]);
    }

    /** @param  iterable<Attachment>  $files  already checked to belong to this person and to be unsent */
    public function addUserMessage(Conversation $c, string $text, iterable $files = []): Message
    {
        $m = Message::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => $text]);
        foreach ($files as $f) {
            $f->update(['message_id' => $m->id, 'conversation_id' => $c->id]);
        }
        $c->update(['last_message_at' => now()]);

        return $m;
    }

    /** An edited message keeps the files of the message it replaces. */
    public function carryFiles(Message $from, Message $to): void
    {
        Attachment::where('message_id', $from->id)->update(['message_id' => $to->id]);
    }

    /**
     * Answer the latest user message. Throws TokenLimitReached, AgentUnavailable or ProviderException; the user's
     * message stays saved so the person can retry.
     */
    public function answer(User $user, Conversation $c): Message
    {
        $agent = $this->agentFor($c);
        $target = null;
        if ($agent) {
            $target = $this->agents->targetFor($agent) ?? throw new AgentUnavailable;
        } elseif ($this->agents->any()) {
            throw new AgentUnavailable; // agents exist but this conversation has none and there is no default to fall back to
        }

        $context = $this->context($c);
        $estimate = (int) ceil(array_sum(array_map(fn ($m) => strlen($m['content']), $context)) / 4);
        $requestId = 'chat-'.Str::lower(Str::random(16));

        $out = $this->runner->run(
            $user, $agent, $requestId, $target['model'] ?? (string) Settings::get('hermes_model_label'),
            fn () => $this->hermes->chat($context, $target),
            'chat', $estimate, $c->id,
        );

        $reply = Message::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => $out['result']['content'], 'request_id' => $requestId]);
        $c->update(['last_message_at' => now()]);
        $this->allowance->notifyThresholds($user, BusinessDay::today());

        return $reply;
    }

    /** The conversation's agent. A conversation from before agents existed adopts the default agent. */
    private function agentFor(Conversation $c): ?Agent
    {
        if ($c->agent_id) {
            return Agent::active()->find($c->agent_id);
        }
        $default = Agent::active()->where('is_default', true)->first();
        if ($default) {
            $c->update(['agent_id' => $default->id]);
        }

        return $default;
    }

    /** Hide the newest answer (kept as history) so a fresh one can be requested. */
    public function supersedeLastAnswer(Conversation $c): void
    {
        $last = $c->messages()->whereNull('superseded_at')->orderByDesc('id')->first();
        if ($last && $last->role === 'assistant') {
            $last->update(['superseded_at' => now()]);
        }
    }

    /** Replace a user message and everything after it. History is kept, hidden. */
    public function supersedeFrom(Conversation $c, Message $from): void
    {
        $c->messages()->whereNull('superseded_at')->where('id', '>=', $from->id)->update(['superseded_at' => now()]);
    }

    /** @return list<array{role: string, content: string}> newest messages that fit, oldest first */
    private function context(Conversation $c): array
    {
        $rows = $c->messages()->whereNull('superseded_at')->orderByDesc('id')->get(['id', 'role', 'content']);
        $files = Attachment::whereIn('message_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('message_id');
        $kept = [];
        $size = 0;
        foreach ($rows as $r) {
            $content = $r->content;
            foreach ($files[$r->id] ?? [] as $f) {
                $content .= "\n\n[Attached file: {$f->original_name}".($f->truncated ? ' (shortened)' : '')."]\n{$f->extracted_text}\n[End of file]";
            }
            $size += strlen($content);
            if ($kept && $size > self::CONTEXT_CHARS) {
                break;
            }
            $kept[] = ['role' => $r->role, 'content' => $content];
        }

        return array_reverse($kept);
    }

    private function titleFrom(string $text): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text));

        return Str::limit($t === '' ? 'New conversation' : $t, 60, '…');
    }
}
