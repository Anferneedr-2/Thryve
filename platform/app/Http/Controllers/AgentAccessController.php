<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Agent;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Which chatbots an employee may use. The default agent is open to everyone and cannot be switched off per person;
 * every other agent is ticked per employee. Admins and Super Admins always get every enabled agent, so there is
 * nothing to set for them. Follows the account boundary: an Admin manages Employees only.
 */
class AgentAccessController extends Controller
{
    public function show(User $user): JsonResponse
    {
        Gate::authorize('manage', $user);

        return Api::ok($this->present($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage', $user);
        if (! $user->hasRole(Role::EMPLOYEE)) {
            return Api::error('not_applicable', 'Admins and Super Admins can use every enabled chatbot.', 422);
        }
        $d = $request->validate(['agent_ids' => ['present', 'array'], 'agent_ids.*' => ['integer', 'distinct']]);
        $assignable = Agent::active()->where('is_default', false)->pluck('id')->all();
        $ids = array_values(array_unique($d['agent_ids']));
        if ($bad = array_diff($ids, $assignable)) {
            return Api::error('validation_failed', 'One of the chatbots cannot be assigned. The default chatbot is open to everyone, and removed ones cannot be given.', 422, ['agent_ids' => ['Choose from the listed chatbots only.']]);
        }

        $before = DB::table('agent_user')->where('user_id', $user->id)->pluck('agent_id')->all();
        DB::transaction(function () use ($user, $ids, $before, $request) {
            DB::table('agent_user')->where('user_id', $user->id)->whereNotIn('agent_id', $ids ?: [0])->delete();
            foreach (array_diff($ids, $before) as $agentId) {
                DB::table('agent_user')->insert(['agent_id' => $agentId, 'user_id' => $user->id, 'granted_by' => $request->user()->id, 'created_at' => now()]);
            }
        });
        Audit::log('agent.access_changed', $request->user(), $user, ['added' => array_values(array_diff($ids, $before)), 'removed' => array_values(array_diff($before, $ids))]);

        return Api::ok($this->present($user));
    }

    private function present(User $user): array
    {
        $assigned = DB::table('agent_user')->where('user_id', $user->id)->pluck('agent_id')->all();
        $all = $user->hasRole(Role::ADMIN, Role::SUPER_ADMIN);

        return [
            'all_access' => $all,
            'agents' => Agent::active()->orderByDesc('is_default')->orderBy('name')->get()->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'description' => $a->description, 'is_default' => $a->is_default, 'is_enabled' => $a->is_enabled,
                'assigned' => $a->is_default || $all || in_array($a->id, $assigned, true),
            ])->values(),
        ];
    }
}
