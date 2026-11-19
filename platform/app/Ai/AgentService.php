<?php

namespace App\Ai;

use App\Models\Agent;
use App\Models\AgentCheck;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agents are Hermes profiles on the VPS. This system generates each agent's access key, keeps it encrypted, and tests
 * the connection when a Super Admin asks (there are no automatic checks). Keys are returned to the caller exactly once,
 * at creation or rotation, and never logged or audited: only a short fingerprint is.
 */
class AgentService
{
    public const OUT_PREFIX = 'thryve_';

    public const IN_PREFIX = 'thryve_in_';

    /** A working test slower than this is reported as "slow". */
    public const SLOW_MS = 3000;

    public const KEEP_CHECKS = 50;

    public function __construct(private HermesClient $hermes) {}

    public static function newKey(string $prefix = self::OUT_PREFIX): string
    {
        return $prefix.bin2hex(random_bytes(32));
    }

    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    /** @return array{agent: Agent, outbound_key: string, inbound_key: string} keys are shown to the admin once */
    public function create(User $actor, array $d): array
    {
        return DB::transaction(function () use ($actor, $d) {
            $out = self::newKey();
            $in = self::newKey(self::IN_PREFIX);
            $first = ! Agent::active()->exists();
            $agent = Agent::create([
                'name' => $d['name'], 'slug' => $this->slugFor($d['name']), 'base_url' => rtrim($d['base_url'], '/'), 'profile' => $d['profile'] ?? null, 'description' => $d['description'] ?? null,
                'is_default' => $first, 'created_by' => $actor->id,
                'outbound_key' => $out, 'outbound_fingerprint' => self::fingerprint($out),
                'inbound_key_hash' => hash('sha256', $in), 'inbound_fingerprint' => self::fingerprint($in),
            ]);
            $agent->refresh();
            Audit::log('agent.created', $actor, null, ['agent_id' => $agent->id, 'name' => $agent->name, 'fingerprint' => $agent->outbound_fingerprint]);

            return ['agent' => $agent, 'outbound_key' => $out, 'inbound_key' => $in];
        });
    }

    private function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'agent';
        $slug = $base;
        for ($i = 2; Agent::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    // ---- what chat uses ---------------------------------------------------------------------

    public function any(): bool
    {
        return Agent::active()->exists();
    }

    /**
     * What a request to this agent needs, or null when it cannot take requests (removed, disabled, or no key).
     *
     * @return array{agent_id: int, url: string, key: string, model: string}|null
     */
    public function targetFor(Agent $a): ?array
    {
        if ($a->removed_at || ! $a->is_enabled || ! $a->outbound_key) {
            return null;
        }

        return ['agent_id' => $a->id, 'url' => $a->url(), 'key' => $a->outbound_key, 'model' => $a->profile ?: (string) config('services.hermes.model')];
    }

    // ---- testing ----------------------------------------------------------------------------

    /** Free check: does the agent answer, does it accept our key, does the profile exist. Spends no tokens. */
    public function test(Agent $agent, ?User $actor, ?string $keyOverride = null, string $kind = 'ping'): AgentCheck
    {
        $key = $keyOverride ?? $agent->outbound_key;
        if (! $key) {
            return $this->record($agent, $actor, $kind, ['category' => 'no_key', 'ok' => false, 'status' => null, 'latency_ms' => null], false);
        }
        $probe = $this->hermes->probe($agent->url(), $key, $agent->profile);

        return $this->record($agent, $actor, $kind, $probe, $keyOverride === null);
    }

    /** One real message through the ledger (counted as background usage). Costs tokens. */
    public function testWithMessage(Agent $agent, User $actor, AgentRunner $runner): AgentCheck
    {
        $target = $this->targetFor($agent);
        if ($target === null) {
            return $this->record($agent, $actor, 'chat', ['category' => 'no_key', 'ok' => false, 'status' => null, 'latency_ms' => null], false);
        }
        $t0 = hrtime(true);
        $tokens = null;
        try {
            $out = $runner->run(null, $agent, 'agent-test-'.Str::lower(Str::random(10)), $target['model'],
                fn () => $this->hermes->chat([['role' => 'user', 'content' => 'Reply with the single word: pong']], $target), 'analysis');
            $tokens = $out['result']['input'] + $out['result']['output'];
            $res = ['category' => 'ok', 'ok' => true, 'status' => 200];
        } catch (TokenLimitReached) {
            $res = ['category' => 'budget_used', 'ok' => false, 'status' => null];
        } catch (ProviderException $e) {
            $res = ['category' => $e->category, 'ok' => false, 'status' => null];
        }
        $res['latency_ms'] = (int) ((hrtime(true) - $t0) / 1e6);

        return $this->record($agent, $actor, 'chat', $res + ['tokens' => $tokens], false);
    }

    /** @param array{category: string, ok: bool, status: int|null, latency_ms: int|null, tokens?: int|null} $r */
    private function record(Agent $agent, ?User $actor, string $kind, array $r, bool $updateAgent): AgentCheck
    {
        $check = AgentCheck::create([
            'agent_id' => $agent->id, 'kind' => $kind, 'category' => $r['category'], 'ok' => $r['ok'], 'status_code' => $r['status'] ?? null,
            'latency_ms' => $r['latency_ms'] ?? null, 'tokens' => $r['tokens'] ?? null, 'user_id' => $actor?->id, 'created_at' => now(),
        ]);
        if ($updateAgent && $agent->status !== 'revoked') {
            $agent->update(['status' => self::statusFor($r), 'last_checked_at' => now(), 'last_latency_ms' => $r['latency_ms'] ?? null]);
        }
        $old = AgentCheck::where('agent_id', $agent->id)->orderByDesc('id')->skip(self::KEEP_CHECKS)->value('id');
        if ($old) {
            AgentCheck::where('agent_id', $agent->id)->where('id', '<=', $old)->delete();
        }
        Audit::log('agent.tested', $actor, null, ['agent_id' => $agent->id, 'kind' => $kind, 'result' => $r['category']]);

        return $check;
    }

    public static function statusFor(array $r): string
    {
        return match (true) {
            $r['ok'] => ($r['latency_ms'] ?? 0) > self::SLOW_MS ? 'slow' : 'working',
            $r['category'] === 'unauthorized' => 'key_rejected',
            $r['category'] === 'profile_missing' => 'profile_missing',
            $r['category'] === 'login_page' => 'wrong_address',
            $r['category'] === 'no_key' => 'revoked',
            default => 'unreachable',
        };
    }

    // ---- rotation: Hermes accepts one key, so a new key is tested before it replaces the old ------

    public function startRotation(Agent $agent, User $actor): string
    {
        $key = self::newKey();
        $agent->update(['pending_key' => $key, 'pending_fingerprint' => self::fingerprint($key)]);
        Audit::log('agent.rotation_started', $actor, null, ['agent_id' => $agent->id, 'fingerprint' => $agent->pending_fingerprint]);

        return $key;
    }

    public function cancelRotation(Agent $agent, User $actor): void
    {
        $agent->update(['pending_key' => null, 'pending_fingerprint' => null]);
        Audit::log('agent.rotation_cancelled', $actor, null, ['agent_id' => $agent->id]);
    }

    /** Test the pending key; only if it works does it replace the active one. */
    public function activateRotation(Agent $agent, User $actor): AgentCheck
    {
        $check = $this->test($agent, $actor, $agent->pending_key, 'verify');
        if ($check->ok) {
            $agent->update([
                'outbound_key' => $agent->pending_key, 'outbound_fingerprint' => $agent->pending_fingerprint,
                'pending_key' => null, 'pending_fingerprint' => null,
                'status' => self::statusFor(['ok' => true, 'category' => 'ok', 'latency_ms' => $check->latency_ms]), 'last_checked_at' => now(), 'last_latency_ms' => $check->latency_ms,
            ]);
            Audit::log('agent.rotated', $actor, null, ['agent_id' => $agent->id, 'fingerprint' => $agent->outbound_fingerprint]);
        } else {
            Audit::log('agent.rotation_failed', $actor, null, ['agent_id' => $agent->id, 'result' => $check->category]);
        }

        return $check;
    }

    /** Stop using the agent: the keys are wiped, so it cannot be called or call in until a new key is issued. */
    public function revoke(Agent $agent, User $actor): void
    {
        $agent->update([
            'outbound_key' => null, 'outbound_fingerprint' => null, 'pending_key' => null, 'pending_fingerprint' => null,
            'inbound_key_hash' => null, 'inbound_fingerprint' => null, 'status' => 'revoked',
        ]);
        Audit::log('agent.revoked', $actor, null, ['agent_id' => $agent->id]);
    }

    /** New key the agent uses to call this system. The old one stops working at once. */
    public function regenerateInbound(Agent $agent, User $actor): string
    {
        $in = self::newKey(self::IN_PREFIX);
        $agent->update(['inbound_key_hash' => hash('sha256', $in), 'inbound_fingerprint' => self::fingerprint($in), 'inbound_last_seen_at' => null]);
        Audit::log('agent.inbound_key_issued', $actor, null, ['agent_id' => $agent->id, 'fingerprint' => $agent->inbound_fingerprint]);

        return $in;
    }

    /** Agent calling in: look it up by the hash of the key it sent. */
    public function authenticateInbound(?string $token): ?Agent
    {
        if (! $token || ! str_starts_with($token, self::IN_PREFIX) || strlen($token) > 200) {
            return null;
        }
        $agent = Agent::active()->where('inbound_key_hash', hash('sha256', $token))->first();

        return $agent && $agent->is_enabled ? $agent : null;
    }
}
