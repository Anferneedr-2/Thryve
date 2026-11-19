<?php

namespace App\Ai;

use App\Models\ProviderConnection;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * Chooses which provider connection serves a request.
 *
 * - Tries enabled, not-removed connections in priority order (lowest number first).
 * - Skips connections that need re-authentication, have used up a KNOWN quota, or recently failed (cooldown).
 *   A connection with unknown quota is never treated as exhausted and never shown as an exact figure.
 * - On a retryable failure it moves to the next connection. When the cooldown ends the failed connection is
 *   simply eligible again, so traffic returns to the preferred connection by itself.
 * - It never sends a request to a second connection after an AMBIGUOUS failure (the first may have been charged).
 * - Every attempt, failed ones included, is written to the usage ledger exactly once.
 */
class ProviderRouter
{
    public function __construct(private Ledger $ledger, private Notifier $notifier) {}

    /** @return Collection<int, ProviderConnection> in the order they would be tried */
    public function eligible(?string $kind = null): Collection
    {
        return ProviderConnection::active()->where('is_enabled', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('priority')->orderBy('id')->get()
            ->filter(function (ProviderConnection $c) {
                // A known quota that has reset is available again.
                if ($c->status === 'quota_exhausted' && $c->quota_resets_at && $c->quota_resets_at->isPast()) {
                    $c->update(['status' => 'unknown', 'quota_used' => 0]);
                }
                if (in_array($c->status, ['reauth_required', 'quota_exhausted'], true)) {
                    return false;
                }
                if ($c->unavailable_until && $c->unavailable_until->isFuture()) {
                    return false;
                }

                return ! ($c->quota_known && $c->quota_limit !== null && (int) $c->quota_used >= (int) $c->quota_limit);
            })->values();
    }

    /**
     * Run one logical request. $attempt receives the connection and must return ['input' => int, 'output' => int]
     * (add 'estimate' => true if the counts are our own), or throw a ProviderException.
     *
     * @return array{connection: ProviderConnection, usage: \App\Models\UsageRecord, result: array}
     *
     * @throws TokenLimitReached when the allowance is used up (nothing is sent)
     * @throws NoProviderAvailable when every eligible connection failed or none exist
     * @throws ProviderException for failures that must not move to another connection
     */
    public function run(?User $user, string $requestId, string $model, callable $attempt, string $purpose = 'chat', int $estimate = 0, ?int $conversationId = null): array
    {
        $connections = $this->eligible();
        if ($connections->isEmpty()) {
            throw new NoProviderAvailable;
        }

        $last = null;
        foreach ($connections->values() as $i => $c) {
            $record = $this->ledger->begin($user, $c, $requestId, $i + 1, $model, $purpose, $estimate, $conversationId);
            $t0 = hrtime(true);

            try {
                $result = $attempt($c);
            } catch (ProviderException $e) {
                $ms = (int) ((hrtime(true) - $t0) / 1e6);
                $this->recordFailure($record, $e, $ms);
                $this->markFailed($c, $e);
                $last = $e->category;
                if ($e->ambiguous || ! $e->retryable) {
                    throw $e; // do not risk a duplicate charge, and do not retry what cannot succeed elsewhere
                }

                continue;
            } catch (\Throwable $e) {
                $this->ledger->finish($record, 'failed', failure: 'error', latencyMs: (int) ((hrtime(true) - $t0) / 1e6));
                throw $e;
            }

            $ms = (int) ((hrtime(true) - $t0) / 1e6);
            $usage = $this->ledger->finish($record, 'success', (int) $result['input'], (int) $result['output'], (bool) ($result['estimate'] ?? false), $ms);
            $this->markAvailable($c);

            return ['connection' => $c->fresh(), 'usage' => $usage, 'result' => $result];
        }

        throw new NoProviderAvailable($last);
    }

    private function recordFailure($record, ProviderException $e, int $ms): void
    {
        if ($e->ambiguous && $e->inputTokens === null) {
            // May have been charged but we do not know how much: keep the reserved estimate, flagged as an estimate.
            $this->ledger->finish($record, 'failed', latencyMs: $ms, failure: $e->category);

            return;
        }
        // The provider did no work (or told us exactly what it did): record the real numbers, zero if none.
        $this->ledger->finish($record, 'failed', (int) ($e->inputTokens ?? 0), (int) ($e->outputTokens ?? 0), false, $ms, $e->category);
    }

    private function markAvailable(ProviderConnection $c): void
    {
        $c->update(['status' => 'available', 'status_checked_at' => now(), 'last_error' => null, 'unavailable_until' => null]);
    }

    private function markFailed(ProviderConnection $c, ProviderException $e): void
    {
        $cooldown = (int) Settings::get('provider_cooldown_seconds');
        $status = match ($e->category) {
            'unauthorized' => 'reauth_required',
            'quota_exhausted' => 'quota_exhausted',
            'bad_request' => $c->status, // the request was at fault, not the connection
            default => 'unavailable',
        };
        $changed = $status !== $c->status;
        $c->update([
            'status' => $status, 'status_checked_at' => now(), 'last_error' => $e->category,
            'unavailable_until' => $status === 'unavailable' ? now()->addSeconds($cooldown) : $c->unavailable_until,
        ]);

        if ($changed && in_array($status, ['reauth_required', 'unavailable', 'quota_exhausted'], true)) {
            $this->alertAdmins($c, $status);
        }
    }

    /** At most one alert per connection and state per hour. */
    public function alertAdmins(ProviderConnection $c, string $status): void
    {
        $type = $status === 'reauth_required' ? 'provider.reauth' : 'provider.failure';
        $title = match ($status) {
            'reauth_required' => "{$c->name} needs to be reconnected",
            'quota_exhausted' => "{$c->name} has used up its quota",
            default => "{$c->name} is not responding",
        };
        $body = $status === 'reauth_required' ? 'The provider rejected the saved credentials. A Super Admin can reconnect it under Providers.' : 'Requests move to the next connection automatically until it recovers.';
        $this->notifier->notify($type, $title, $body, ['provider_id' => $c->id, 'status' => $status], "prov:{$c->id}:{$status}:".now()->format('Ymd-H'));
    }
}
