<?php

namespace App\Analysis;

use App\Ai\AgentRunner;
use App\Ai\AgentService;
use App\Ai\HermesClient;
use App\Ai\ProviderException;
use App\Ai\TokenLimitReached;
use App\Models\Agent;
use App\Models\AnalysisTopic;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\Message;
use App\Support\Settings;

/**
 * Sends finished conversations to the report agent, one at a time, and stores what comes back.
 *
 * Only this class talks to the report agent, and only the scheduled command runs it: opening a report never does.
 * Cost goes to the separate system allowance (purpose "analysis"), never to an employee. Failed work is stored as
 * failed, never as scores, and a failing agent stops the batch instead of being hammered.
 */
class ConversationAnalyzer
{
    public const MAX_ATTEMPTS = 3;

    /** Run outcomes for one conversation. */
    public const VALID = 'valid';

    public const UNASSESSABLE = 'unassessable';

    public const FAILED = 'failed';

    public const STALE = 'stale';

    public const STOPPED = 'stopped';

    /** How many candidate conversations one run looks at, so a big backlog never makes a run slow. */
    private const SCAN_LIMIT = 400;

    public function __construct(private HermesClient $hermes, private AgentService $agents, private AgentRunner $runner) {}

    /** The agent that does the analysis: the report agent, if it is enabled and has a key. */
    public function agent(): ?Agent
    {
        return Agent::active()->report()->where('is_enabled', true)->whereNotNull('outbound_key')->orderBy('id')->first();
    }

    public function enabled(): bool
    {
        return (bool) Settings::get('analysis_enabled') && $this->agent() !== null;
    }

    /**
     * Analyze up to $batch due conversations, newest first, for at most $seconds.
     *
     * @return array{status: string, valid: int, unassessable: int, failed: int, stale: int, stopped: string|null}
     */
    public function run(int $batch, int $seconds = 45): array
    {
        $out = ['status' => 'ran', 'valid' => 0, 'unassessable' => 0, 'failed' => 0, 'stale' => 0, 'stopped' => null];
        $agent = $this->agent();
        if (! (bool) Settings::get('analysis_enabled') || $agent === null) {
            return ['status' => 'off'] + $out;
        }
        if (! AnalysisTopic::active()->exists()) {
            return ['status' => 'no_topics'] + $out;
        }

        $deadline = microtime(true) + $seconds;
        $done = 0;
        foreach ($this->candidates() as $c) {
            if ($done >= $batch || microtime(true) >= $deadline) {
                break;
            }
            $messages = $this->messagesOf($c);
            $hash = AnalysisContract::versionHash($messages);
            if (! $this->isDue($c, $hash)) {
                continue;
            }
            $done++;
            $result = $this->analyze($c, $agent, $messages, $hash);
            if ($result['outcome'] === self::STOPPED) {
                $out['stopped'] = $result['reason'];
                break;
            }
            $out[$result['outcome']]++;
        }

        return $out;
    }

    /**
     * Conversations worth a closer look: not deleted by their owner, quiet for the configured time, and without a stored
     * result newer than their last message. Archived and old conversations count. Newest first.
     *
     * @return \Generator<Conversation>
     */
    private function candidates(): \Generator
    {
        $idle = max(1, (int) Settings::get('analysis_idle_minutes'));
        $query = Conversation::query()
            ->whereNull('deleted_at')
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<=', now()->subMinutes($idle))
            ->whereExists(fn ($m) => $m->selectRaw('1')->from('messages')->whereColumn('messages.conversation_id', 'conversations.id')
                ->where('role', 'user')->whereNull('superseded_at')->where('content', '!=', ''))
            ->whereNotExists(fn ($a) => $a->selectRaw('1')->from('conversation_analyses')->whereColumn('conversation_analyses.conversation_id', 'conversations.id')
                ->where('rubric_version', AnalysisContract::RUBRIC_VERSION)->whereIn('status', [ConversationAnalysis::VALID, ConversationAnalysis::UNASSESSABLE])
                ->whereColumn('conversation_analyses.analyzed_at', '>=', 'conversations.last_message_at'))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(self::SCAN_LIMIT);

        yield from $query->get();
    }

    /** Is there still work to do for exactly this version of the conversation? */
    private function isDue(Conversation $c, string $hash): bool
    {
        $row = ConversationAnalysis::where(['conversation_id' => $c->id, 'version_hash' => $hash, 'rubric_version' => AnalysisContract::RUBRIC_VERSION])->first();
        if ($row === null) {
            return true;
        }
        if ($row->status !== ConversationAnalysis::FAILED) {
            return false; // already has a stored result for this version
        }

        return $row->attempts < self::MAX_ATTEMPTS && $row->next_retry_at !== null && $row->next_retry_at->lte(now());
    }

    /** The current messages the agent will see: not replaced, not failed, not empty, oldest first. */
    private function messagesOf(Conversation $c): array
    {
        return Message::where('conversation_id', $c->id)->whereNull('superseded_at')->where('status', '!=', 'failed')->where('content', '!=', '')
            ->orderBy('id')->get(['id', 'role', 'content'])->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'content' => $m->content])->all();
    }

    /**
     * Analyze one conversation and store the outcome.
     *
     * @param  list<array{id: int, role: string, content: string}>  $messages
     * @return array{outcome: string, reason?: string}
     */
    public function analyze(Conversation $c, Agent $agent, array $messages, string $hash): array
    {
        $employeeCount = count(array_filter($messages, fn ($m) => $m['role'] === 'user'));
        $key = ['conversation_id' => $c->id, 'version_hash' => $hash, 'rubric_version' => AnalysisContract::RUBRIC_VERSION];
        $base = ['user_id' => $c->user_id, 'agent_id' => $agent->id, 'message_count' => count($messages), 'employee_message_count' => $employeeCount];
        $attempts = (int) ConversationAnalysis::where($key)->value('attempts') + 1;

        if ($employeeCount === 0) {
            $this->store($key, $base + ['status' => ConversationAnalysis::UNASSESSABLE, 'error' => 'no_employee_message', 'attempts' => $attempts, 'analyzed_at' => now(), 'next_retry_at' => null]);

            return ['outcome' => self::UNASSESSABLE];
        }
        $target = $this->agents->targetFor($agent);
        $topics = AnalysisTopic::active()->ordered()->get(['id', 'name', 'description']);
        if ($target === null || $topics->isEmpty()) {
            return ['outcome' => self::STOPPED, 'reason' => $target === null ? 'agent_unavailable' : 'no_topics'];
        }

        $ref = 'an_'.bin2hex(random_bytes(6));
        $request = AnalysisContract::buildRequest($ref, $this->withFiles($messages), $topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'description' => $t->description])->all());

        try {
            $run = $this->runner->run(null, $agent, $ref, $target['model'], fn () => $this->hermes->chat([['role' => 'user', 'content' => $request]], $target),
                'analysis', (int) ceil(strlen($request) / 4) + 300, $c->id);
        } catch (TokenLimitReached) {
            return ['outcome' => self::STOPPED, 'reason' => 'budget'];
        } catch (ProviderException $e) {
            $this->fail($key, $base, $attempts, $e->category, $this->retryMinutes($e->category), $ref);

            return ['outcome' => self::STOPPED, 'reason' => $e->category]; // a failing agent ends the batch
        }

        try {
            $reply = AnalysisContract::parse((string) $run['result']['content'], $ref, $topics->pluck('id')->all());
        } catch (AnalysisInvalid $e) {
            $this->fail($key, $base, $attempts, 'invalid:'.$e->reason, 15, $ref);

            return ['outcome' => self::FAILED];
        }

        // The conversation may have moved on while the agent was thinking: that result describes an old version.
        if (AnalysisContract::versionHash($this->messagesOf($c)) !== $hash) {
            return ['outcome' => self::STALE];
        }

        if ($reply['status'] === 'unassessable') {
            $this->store($key, $base + ['status' => ConversationAnalysis::UNASSESSABLE, 'error' => $reply['detail'], 'attempts' => $attempts, 'request_id' => $ref, 'analyzed_at' => now(), 'next_retry_at' => null]);

            return ['outcome' => self::UNASSESSABLE];
        }

        $sum = AnalysisContract::summarize($reply['scores'], $employeeCount);
        $this->store($key, $base + [
            'status' => ConversationAnalysis::VALID, 'error' => null, 'attempts' => $attempts, 'request_id' => $ref, 'analyzed_at' => now(), 'next_retry_at' => null,
            'task_label' => $reply['task_label'], 'topic_ids' => $reply['topic_ids'], 'tip' => $reply['tip'],
            'clarity' => $sum['scores']['clarity'], 'context' => $sum['scores']['context'], 'specifics' => $sum['scores']['specifics'],
            'refinement' => $sum['scores']['refinement'], 'verification' => $sum['scores']['verification'],
            'dimensions_scored' => $sum['dimensions_scored'], 'average' => $sum['average'],
        ]);

        return ['outcome' => self::VALID];
    }

    /** Attach the names and types (never the contents) of each employee message's files. */
    private function withFiles(array $messages): array
    {
        $ids = array_column($messages, 'id');
        $files = Attachment::whereIn('message_id', $ids)->where('generated', false)->orderBy('id')->get(['message_id', 'original_name', 'kind'])->groupBy('message_id');

        return array_map(function ($m) use ($files) {
            if ($m['role'] === 'user' && isset($files[$m['id']])) {
                $m['files'] = $files[$m['id']]->map(fn ($f) => ['name' => $f->original_name, 'type' => $f->kind])->all();
            }

            return $m;
        }, $messages);
    }

    private function store(array $key, array $values): void
    {
        ConversationAnalysis::updateOrCreate($key, $values);
    }

    /** Record a failure. After the last allowed attempt there is no retry time, so it is never tried again. */
    private function fail(array $key, array $base, int $attempts, string $error, int $retryMinutes, string $ref): void
    {
        $retry = $retryMinutes > 0 && $attempts < self::MAX_ATTEMPTS ? now()->addMinutes($retryMinutes) : null;
        $this->store($key, $base + [
            'status' => ConversationAnalysis::FAILED, 'error' => substr($error, 0, 32), 'attempts' => $attempts, 'request_id' => $ref, 'analyzed_at' => null, 'next_retry_at' => $retry,
        ]);
    }

    /** How long to wait before trying a failed conversation again (0 = never). Never an immediate repeat. */
    private function retryMinutes(string $category): int
    {
        return match ($category) {
            'bad_request' => 0,
            'timeout' => 60, // the agent may still be working on it: do not repeat soon
            'bad_response' => 15,
            default => 30, // rate limited, unavailable, key rejected
        };
    }
}
