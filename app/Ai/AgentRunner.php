<?php

namespace App\Ai;

use App\Models\Agent;
use App\Models\UsageRecord;
use App\Models\User;
use App\Notifications\Notifier;

/**
 * Runs one request against one agent and records it in the ledger. There is no failover: an agent is a single
 * chatbot, so a failure is reported, recorded exactly once, and (for the problems an admin can fix) alerted.
 * A request that may already have been charged is never repeated.
 */
class AgentRunner
{
    public function __construct(private Ledger $ledger, private Notifier $notifier) {}

    /**
     * @param  callable(): array{input: int, output: int, estimate?: bool}  $attempt  returns real counts or throws ProviderException
     * @return array{usage: UsageRecord, result: array}
     *
     * @throws AgentUnavailable the agent cannot take requests at all (missing, disabled or revoked)
     * @throws TokenLimitReached
     * @throws ProviderException
     */
    public function run(?User $user, ?Agent $agent, string $requestId, string $model, callable $attempt, string $purpose = 'chat', int $estimate = 0, ?int $conversationId = null, ?string $requestKind = null): array
    {
        $record = $this->ledger->begin($user, $agent?->id, $requestId, 1, $model, $purpose, $estimate, $conversationId, $requestKind);
        $t0 = hrtime(true);
        $ms = fn () => (int) ((hrtime(true) - $t0) / 1e6);

        try {
            $result = $attempt();
        } catch (ProviderException $e) {
            $this->recordFailure($record, $e, $ms());
            $this->alertAdmins($agent, $e->category);
            throw $e;
        } catch (\Throwable $e) {
            $this->ledger->finish($record, 'failed', failure: 'error', latencyMs: $ms());
            throw $e;
        }

        $usage = $this->ledger->finish($record, ($result['finish'] ?? null) === 'cancelled' ? 'cancelled' : 'success', (int) $result['input'], (int) $result['output'], (bool) ($result['estimate'] ?? false), $ms());

        return ['usage' => $usage, 'result' => $result];
    }

    private function recordFailure($record, ProviderException $e, int $ms): void
    {
        if ($e->ambiguous && $e->inputTokens === null) {
            // May have been charged but we do not know how much: keep the reserved estimate, flagged as an estimate.
            $this->ledger->finish($record, 'failed', latencyMs: $ms, failure: $e->category);

            return;
        }
        // The agent did no work (or told us exactly what it did): record the real numbers, zero if none.
        $this->ledger->finish($record, 'failed', (int) ($e->inputTokens ?? 0), (int) ($e->outputTokens ?? 0), $e->isEstimate, $ms, $e->category);
    }

    /** Tell admins about problems they can fix, at most once per agent and kind per hour. */
    private function alertAdmins(?Agent $agent, string $category): void
    {
        $name = $agent?->name ?? 'The AI server';
        [$type, $title, $body] = match ($category) {
            'unauthorized' => ['provider.reauth', "{$name} rejected its access key", 'A Super Admin can check the key under AI agents: press Test, or rotate the key.'],
            'unavailable', 'timeout', 'rate_limited' => ['provider.failure', "{$name} is not responding", 'Employees see a "try again" message until it recovers. A Super Admin can press Test under AI agents.'],
            default => [null, null, null],
        };
        if ($type === null) {
            return;
        }
        $this->notifier->notify($type, $title, $body, ['agent_id' => $agent?->id, 'kind' => $category], 'agent:'.($agent?->id ?? 0).":{$type}:".now()->format('Ymd-H'));
    }
}
