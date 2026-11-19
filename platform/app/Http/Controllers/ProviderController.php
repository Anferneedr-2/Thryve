<?php

namespace App\Http\Controllers;

use App\Ai\HermesClient;
use App\Ai\ProviderRouter;
use App\Http\Responses\Api;
use App\Models\ProviderConnection;
use App\Notifications\Notifier;
use App\Support\Audit;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Provider connections. Admins and Super Admins can see status and usage (read-only); only a Super Admin can connect,
 * reconnect, enable, disable, reorder or remove (route guard). Credentials are write-only: never returned, never logged.
 */
class ProviderController extends Controller
{
    private function present(ProviderConnection $c, array $usageToday): array
    {
        $u = $usageToday[$c->id] ?? null;

        return [
            'id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'priority' => $c->priority, 'is_enabled' => $c->is_enabled,
            'status' => $c->status, 'status_checked_at' => $c->status_checked_at?->toIso8601String(), 'last_error' => $c->last_error,
            'unavailable_until' => $c->unavailable_until?->isFuture() ? $c->unavailable_until->toIso8601String() : null,
            'has_credentials' => $c->credentials !== null && $c->credentials !== '',
            // When the provider gives no figure we say so. A guessed number is never shown as exact.
            'quota' => $c->quota_known ? [
                'known' => true, 'limit' => $c->quota_limit, 'used' => $c->quota_used, 'resets_at' => $c->quota_resets_at?->toIso8601String(),
                'percent' => $c->quota_limit ? min(100, (int) floor($c->quota_used * 100 / $c->quota_limit)) : null,
            ] : ['known' => false, 'limit' => null, 'used' => null, 'resets_at' => null, 'percent' => null],
            'usage_today' => ['tokens' => (int) ($u->tokens ?? 0), 'requests' => (int) ($u->requests ?? 0), 'failures' => (int) ($u->failures ?? 0)],
        ];
    }

    private function usageToday(): array
    {
        return DB::table('usage_records')->where('work_date', BusinessDay::today())->groupBy('provider_connection_id')
            ->selectRaw("provider_connection_id as id, SUM(input_tokens + output_tokens) as tokens, COUNT(*) as requests, SUM(status = 'failed') as failures")
            ->get()->keyBy('id')->all();
    }

    public function index(): JsonResponse
    {
        $usage = $this->usageToday();

        return Api::ok(ProviderConnection::active()->orderBy('priority')->orderBy('id')->get()->map(fn ($c) => $this->present($c, $usage))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(ProviderConnection::KINDS)],
            'credentials' => ['required', 'string', 'max:4096'],
            'priority' => ['nullable', 'integer', 'between:1,100000'],
        ]);
        $c = ProviderConnection::create([
            'name' => $d['name'], 'kind' => $d['kind'], 'credentials' => $d['credentials'], 'created_by' => $request->user()->id,
            'priority' => $d['priority'] ?? ((int) ProviderConnection::active()->max('priority') + 10),
        ]);
        $c->refresh(); // pick up the database defaults (status, enabled)
        Audit::log('provider.created', $request->user(), null, ['provider_id' => $c->id, 'name' => $c->name, 'kind' => $c->kind]);

        return Api::ok($this->present($c, []), status: 201);
    }

    public function update(Request $request, ProviderConnection $provider): JsonResponse
    {
        $this->assertActive($provider);
        $d = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'is_enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'between:1,100000'],
        ]);
        $provider->update($d);
        if ($d) {
            Audit::log('provider.updated', $request->user(), null, ['provider_id' => $provider->id, 'fields' => array_keys($d)] + (isset($d['is_enabled']) ? ['enabled' => (bool) $d['is_enabled']] : []));
        }

        return Api::ok($this->present($provider->fresh(), $this->usageToday()));
    }

    public function reorder(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $ids = $d['ids'];
        $active = ProviderConnection::active()->pluck('id')->all();
        if (array_diff($ids, $active) || array_diff($active, $ids)) {
            return Api::error('validation_failed', 'Send every connection exactly once.', 422, ['ids' => ['Send every connection exactly once.']]);
        }
        DB::transaction(function () use ($ids) {
            foreach ($ids as $i => $id) {
                ProviderConnection::whereKey($id)->update(['priority' => ($i + 1) * 10]);
            }
        });
        Audit::log('provider.reordered', $request->user(), null, ['order' => $ids]);

        return $this->index();
    }

    /** Replace the saved credentials (the provider rejected them, or they were rotated). */
    public function reauthenticate(Request $request, ProviderConnection $provider): JsonResponse
    {
        $this->assertActive($provider);
        $d = $request->validate(['credentials' => ['required', 'string', 'max:4096']]);
        $provider->update(['credentials' => $d['credentials'], 'status' => 'unknown', 'last_error' => null, 'unavailable_until' => null, 'status_checked_at' => null]);
        Audit::log('provider.reauthenticated', $request->user(), null, ['provider_id' => $provider->id]);

        return Api::ok($this->present($provider->fresh(), $this->usageToday()));
    }

    /**
     * Check the connection now. Until the Hermes contract is confirmed (decision D02) this can only test that Hermes
     * answers and accepts its key; it cannot test an individual provider account behind it.
     */
    public function check(Request $request, ProviderConnection $provider, HermesClient $hermes, ProviderRouter $router): JsonResponse
    {
        $this->assertActive($provider);
        $ping = $hermes->ping();
        if ($ping['category'] === 'not_configured') {
            return Api::ok(['checked' => false, 'message' => 'Hermes is not connected yet, so this connection cannot be checked.', 'provider' => $this->present($provider, $this->usageToday())]);
        }

        $previous = $provider->status;
        $status = match ($ping['category']) {
            'ok' => in_array($previous, ['reauth_required', 'quota_exhausted'], true) ? $previous : 'available',
            'unauthorized' => 'reauth_required',
            default => 'unavailable',
        };
        $provider->update([
            'status' => $status, 'status_checked_at' => now(), 'last_error' => $ping['ok'] ? null : $ping['category'],
            'unavailable_until' => $status === 'unavailable' ? now()->addSeconds((int) \App\Support\Settings::get('provider_cooldown_seconds')) : null,
        ]);
        if ($status !== $previous && in_array($status, ['reauth_required', 'unavailable'], true)) {
            $router->alertAdmins($provider, $status);
        }
        Audit::log('provider.checked', $request->user(), null, ['provider_id' => $provider->id, 'result' => $ping['category']]);

        return Api::ok(['checked' => true, 'result' => $ping['category'], 'message' => $ping['ok'] ? 'Hermes answered.' : 'Hermes did not answer as expected ('.$ping['category'].').', 'provider' => $this->present($provider->fresh(), $this->usageToday())]);
    }

    /**
     * Record the provider's quota by hand when the provider offers no way to read it (decision D03). Alerts admins once
     * each at 80, 90 and 100 percent for the current quota window. A connection at 100% is set aside by the router.
     */
    public function quota(Request $request, ProviderConnection $provider, Notifier $notifier): JsonResponse
    {
        $this->assertActive($provider);
        $d = $request->validate([
            'limit' => ['required', 'integer', 'min:1'], 'used' => ['required', 'integer', 'min:0'], 'resets_at' => ['nullable', 'date'],
        ]);
        $provider->update(['quota_known' => true, 'quota_limit' => $d['limit'], 'quota_used' => $d['used'], 'quota_resets_at' => $d['resets_at'] ?? null]);
        if ($d['used'] >= $d['limit'] && $provider->status !== 'reauth_required') {
            $provider->update(['status' => 'quota_exhausted']);
        } elseif ($provider->status === 'quota_exhausted') {
            $provider->update(['status' => 'unknown']);
        }

        $percent = (int) floor($d['used'] * 100 / $d['limit']);
        $window = $provider->quota_resets_at?->format('Ymd-His') ?? BusinessDay::today();
        foreach ([80, 90, 100] as $t) {
            if ($percent >= $t) {
                $notifier->notify('provider.threshold', "{$provider->name} has used {$t}% of its quota", $t >= 100 ? 'Requests move to the next connection until it resets.' : null, ['provider_id' => $provider->id, 'percent' => $t], "prov:{$provider->id}:quota:{$window}:{$t}");
            }
        }
        Audit::log('provider.quota_recorded', $request->user(), null, ['provider_id' => $provider->id, 'percent' => $percent]);

        return Api::ok($this->present($provider->fresh(), $this->usageToday()));
    }

    /** Removal keeps usage history: the connection is retired and its credentials wiped. */
    public function destroy(Request $request, ProviderConnection $provider): JsonResponse
    {
        $this->assertActive($provider);
        $provider->update(['removed_at' => now(), 'is_enabled' => false, 'credentials' => null, 'status' => 'unknown']);
        Audit::log('provider.removed', $request->user(), null, ['provider_id' => $provider->id, 'name' => $provider->name]);

        return Api::ok(['removed' => true]);
    }

    private function assertActive(ProviderConnection $p): void
    {
        abort_if($p->removed_at !== null, 404);
    }
}
