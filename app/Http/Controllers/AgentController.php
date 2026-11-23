<?php

namespace App\Http\Controllers;

use App\Ai\AgentRunner;
use App\Ai\AgentService;
use App\Http\Responses\Api;
use App\Models\Agent;
use App\Models\AgentCheck;
use App\Models\User;
use App\Support\AgentAvatar;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AI agents (Hermes profiles). Admins and Super Admins can look; only a Super Admin changes anything (route guard).
 * Keys appear in a response exactly once (create, rotate, new inbound key) and never in any read.
 */
class AgentController extends Controller
{
    public function __construct(private AgentService $agents) {}

    private function find(int|string $id): Agent
    {
        return Agent::active()->findOrFail($id);
    }

    public function index(): JsonResponse
    {
        return Api::ok(Agent::active()->orderByDesc('is_default')->orderBy('id')->get()->map(fn ($a) => $this->present($a)));
    }

    public function checks(int $id): JsonResponse
    {
        $a = $this->find($id);

        return Api::ok(AgentCheck::where('agent_id', $a->id)->orderByDesc('id')->limit(20)->get()->map(fn ($c) => [
            'id' => $c->id, 'kind' => $c->kind, 'category' => $c->category, 'ok' => $c->ok, 'latency_ms' => $c->latency_ms,
            'tokens' => $c->tokens, 'by' => $c->user_id ? User::whereKey($c->user_id)->value('name') : null, 'at' => $c->created_at->toIso8601String(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules() + ['type' => ['sometimes', Rule::in([Agent::TYPE_CHAT, Agent::TYPE_REPORT])]], $this->messages());
        if ($bad = $this->addressProblem($d['base_url'])) {
            return Api::error('validation_failed', $bad, 422, ['base_url' => [$bad]]);
        }
        if (($d['type'] ?? Agent::TYPE_CHAT) === Agent::TYPE_REPORT && Agent::active()->report()->exists()) {
            return Api::error('report_agent_exists', 'A report agent is already set up. Remove it first if you want to replace it.', 422, ['type' => ['Only one report agent can be set up.']]);
        }
        $made = $this->agents->create($request->user(), $d);

        return Api::ok($this->present($made['agent']) + ['keys' => ['outbound' => $made['outbound_key'], 'inbound' => $made['inbound_key']]], status: 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $a = $this->find($id);
        $d = $request->validate(array_map(fn ($r) => array_merge(['sometimes'], $r), $this->rules(false)) + ['is_enabled' => ['sometimes', 'boolean'], 'is_default' => ['sometimes', 'boolean']], $this->messages());
        if (isset($d['base_url']) && ($bad = $this->addressProblem($d['base_url']))) {
            return Api::error('validation_failed', $bad, 422, ['base_url' => [$bad]]);
        }
        if ($a->isReport() && ! empty($d['is_default'])) {
            return Api::error('validation_failed', 'A report agent is not a chatbot, so it cannot be the default.', 422, ['is_default' => ['A report agent cannot be the default chatbot.']]);
        }
        if (($d['is_default'] ?? null) === false && $a->is_default) {
            return Api::error('validation_failed', 'Choose another agent as the default instead.', 422, ['is_default' => ['Choose another agent as the default instead.']]);
        }
        if (($d['is_enabled'] ?? null) === false && $a->is_default) {
            return Api::error('default_agent', 'Chat uses the default agent. Make another agent the default before disabling this one.', 422);
        }
        $addressChanged = (isset($d['base_url']) && rtrim($d['base_url'], '/') !== $a->base_url) || (array_key_exists('profile', $d) && ($d['profile'] ?? null) !== $a->profile);
        DB::transaction(function () use ($a, $d, $addressChanged) {
            if (! empty($d['is_default'])) {
                Agent::where('id', '!=', $a->id)->update(['is_default' => false]);
            }
            if (isset($d['base_url'])) {
                $d['base_url'] = rtrim($d['base_url'], '/');
            }
            $a->update($d + ($addressChanged ? ['status' => $a->status === 'revoked' ? 'revoked' : 'never_tested', 'last_checked_at' => null, 'last_latency_ms' => null] : []));
        });
        Audit::log('agent.updated', $request->user(), null, ['agent_id' => $a->id, 'fields' => array_keys($d)]);

        return Api::ok($this->present($a->fresh()));
    }

    public function test(Request $request, int $id, AgentRunner $runner): JsonResponse
    {
        $a = $this->find($id);
        $d = $request->validate(['with_message' => ['nullable', 'boolean']]);
        $check = ($d['with_message'] ?? false)
            ? $this->agents->testWithMessage($a, $request->user(), $runner)
            : $this->agents->test($a, $request->user());

        return Api::ok(['check' => $this->presentCheck($check), 'agent' => $this->present($a->fresh())]);
    }

    public function rotate(Request $request, int $id): JsonResponse
    {
        $a = $this->find($id);
        $key = $this->agents->startRotation($a, $request->user());

        return Api::ok($this->present($a->fresh()) + ['keys' => ['outbound' => $key]]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        $a = $this->find($id);
        if (! $a->pending_key) {
            return Api::error('no_pending_key', 'There is no new key waiting. Start a rotation first.', 409);
        }
        $check = $this->agents->activateRotation($a, $request->user());

        return Api::ok(['activated' => $check->ok, 'check' => $this->presentCheck($check), 'agent' => $this->present($a->fresh())]);
    }

    public function cancelRotation(Request $request, int $id): JsonResponse
    {
        $a = $this->find($id);
        $this->agents->cancelRotation($a, $request->user());

        return Api::ok($this->present($a->fresh()));
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $a = $this->find($id);
        $this->agents->revoke($a, $request->user());

        return Api::ok($this->present($a->fresh()));
    }

    public function inboundKey(Request $request, int $id): JsonResponse
    {
        $a = $this->find($id);
        $key = $this->agents->regenerateInbound($a, $request->user());

        return Api::ok($this->present($a->fresh()) + ['keys' => ['inbound' => $key]]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $a = $this->find($id);
        if ($a->is_default && Agent::active()->chat()->count() > 1) {
            return Api::error('default_agent', 'Make another agent the default before removing this one.', 422);
        }
        $this->agents->revoke($a, $request->user());
        $a->update(['removed_at' => now(), 'is_enabled' => false, 'is_default' => false]); // usage history keeps pointing at it
        Audit::log('agent.removed', $request->user(), null, ['agent_id' => $a->id, 'name' => $a->name]);

        return Api::ok(null);
    }

    /** What an agent sends to prove who it is. Outside the browser session: no cookies, no CSRF, one tiny answer. */
    public function ping(Request $request): JsonResponse
    {
        $agent = $this->agents->authenticateInbound($request->bearerToken());
        if (! $agent) {
            return Api::error('unauthenticated', 'Unauthenticated.', 401);
        }
        $agent->update(['inbound_last_seen_at' => now()]);

        return Api::ok(['agent' => $agent->slug, 'time' => now()->toIso8601String()]);
    }

    /** What the person sees under a field: what is wrong and what to type instead. */
    private function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the agent.',
            'name.max' => 'Use 100 characters or fewer.',
            'base_url.required' => 'Enter the server address, for example https://ai.yourcompany.com.',
            'base_url.url' => 'Enter a full address that starts with https:// (or http:// on this computer), for example https://ai.yourcompany.com.',
            'base_url.max' => 'That address is too long.',
            'profile.regex' => 'Use lowercase letters, numbers, dashes or underscores, starting with a letter or number, for example thryve-bot.',
            'description.max' => 'Use 200 characters or fewer.',
        ];
    }

    private function rules(bool $required = true): array
    {
        $req = $required ? 'required' : 'nullable';

        return [
            'name' => [$required ? 'required' : 'string', 'string', 'min:1', 'max:100'],
            'base_url' => [$required ? 'required' : 'string', 'string', 'max:255', 'url:http,https'],
            'profile' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            'description' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** HTTPS everywhere except this machine; no addresses with credentials, query strings or cloud metadata hosts. */
    private function addressProblem(string $url): ?string
    {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        if (isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return 'Enter just the server address, with no username, password or query.';
        }
        if (($p['scheme'] ?? '') !== 'https' && ! $local) {
            return 'Use an https:// address so the access key is encrypted on its way to the server.';
        }
        if (preg_match('/^169\.254\./', $host) || $host === 'metadata.google.internal') {
            return 'This address is not allowed.';
        }

        return null;
    }

    private function presentCheck(AgentCheck $c): array
    {
        return ['kind' => $c->kind, 'category' => $c->category, 'ok' => $c->ok, 'latency_ms' => $c->latency_ms, 'tokens' => $c->tokens, 'at' => $c->created_at->toIso8601String()];
    }

    private function present(Agent $a): array
    {
        return [
            'id' => $a->id, 'type' => $a->type, 'name' => $a->name, 'slug' => $a->slug, 'base_url' => $a->base_url, 'profile' => $a->profile, 'description' => $a->description, 'url' => $a->url(),
            'avatar' => AgentAvatar::url($a->id),
            'is_enabled' => $a->is_enabled, 'is_default' => $a->is_default, 'status' => $a->status,
            'last_checked_at' => $a->last_checked_at?->toIso8601String(), 'last_latency_ms' => $a->last_latency_ms,
            'has_key' => $a->outbound_key !== null, 'key_fingerprint' => $a->outbound_fingerprint,
            'pending_fingerprint' => $a->pending_fingerprint,
            'inbound_fingerprint' => $a->inbound_fingerprint, 'inbound_last_seen_at' => $a->inbound_last_seen_at?->toIso8601String(),
        ];
    }
}
