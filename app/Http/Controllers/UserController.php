<?php

namespace App\Http\Controllers;

use App\Attendance\Tracker;
use App\Http\Resources\UserResource;
use App\Http\Responses\Api;
use App\Jobs\SendInvitationEmail;
use App\Mail\MailConfigurator;
use App\Models\Agent;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(array_keys(Role::ALL))],
            'active' => ['nullable', 'boolean'],
            'department_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $q = User::with('role', 'departments')->orderBy('name')->orderBy('id');
        if (! empty($f['search'])) {
            $term = '%'.addcslashes($f['search'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('username', 'like', $term)->orWhere('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        if (! empty($f['role'])) {
            $q->whereHas('role', fn ($r) => $r->where('slug', $f['role']));
        }
        if (isset($f['active'])) {
            $q->where('is_active', (bool) $f['active']);
        }
        if (! empty($f['department_id'])) {
            $q->whereHas('departments', fn ($d) => $d->where('departments.id', $f['department_id']));
        }

        $page = $q->paginate($f['per_page'] ?? 25);

        return Api::ok(
            UserResource::collection($page->getCollection())->resolve(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return Api::ok(UserResource::make($user->load('role', 'departments'))->resolve());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $d = $request->validate([
            'username' => ['required', 'regex:/^[A-Za-z0-9._-]{3,64}$/', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:'.config('thryve.min_password_length'), 'max:255'],
            'role' => ['required', Rule::in(array_keys(Role::ALL))],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'daily_token_limit' => ['nullable', 'integer', 'min:0'],
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer', 'distinct'],
        ]);
        Gate::authorize('assignRole', [User::class, $d['role']]);

        $agentIds = array_values(array_unique($d['agent_ids'] ?? []));
        if ($agentIds && $d['role'] !== Role::EMPLOYEE) {
            return Api::error('not_applicable', 'Admins and Super Admins can use every enabled chatbot.', 422);
        }
        if (array_diff($agentIds, Agent::assignableIds())) {
            return Api::error('validation_failed', 'One of the chatbots cannot be assigned. The default chatbot is open to everyone, and removed ones cannot be given.', 422, ['agent_ids' => ['Choose from the listed chatbots only.']]);
        }

        $user = DB::transaction(function () use ($d, $agentIds, $request) {
            $user = new User(['username' => $d['username'], 'name' => $d['name'], 'email' => $d['email'] ?? null, 'password' => $d['password']]);
            $user->role_id = Role::where('slug', $d['role'])->firstOrFail()->id;
            $user->is_active = true;
            $user->must_change_password = true; // the admin chose this password; the person picks their own at first sign-in
            $user->daily_token_limit = $d['daily_token_limit'] ?? null;
            $user->save();
            $user->departments()->sync($d['department_ids'] ?? []);
            foreach ($agentIds as $agentId) {
                DB::table('agent_user')->insert(['agent_id' => $agentId, 'user_id' => $user->id, 'granted_by' => $request->user()->id, 'created_at' => now()]);
            }

            return $user;
        });
        Audit::log('account.created', $request->user(), $user, ['role' => $d['role'], 'departments' => $d['department_ids'] ?? []]);
        if ($agentIds) {
            Audit::log('agent.access_changed', $request->user(), $user, ['added' => $agentIds, 'removed' => []]);
        }

        return Api::ok(UserResource::make($user->load('role', 'departments'))->resolve() + ['invitation' => $this->sendInvitation($request, $user, $d['password'])], status: 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage', $user);
        $d = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in(array_keys(Role::ALL))],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
            'daily_token_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        if (isset($d['role']) && $d['role'] !== $user->role->slug) {
            Gate::authorize('assignRole', [User::class, $d['role']]);
            if ($user->is($request->user())) {
                return Api::error('cannot_modify_self', 'You cannot change your own role.', 422);
            }
            if ($user->hasRole(Role::SUPER_ADMIN) && $this->isLastActiveSuperAdmin($user)) {
                return Api::error('last_super_admin', 'There must always be at least one active Super Admin.', 422);
            }
        }

        $changed = [];
        DB::transaction(function () use ($d, $user, &$changed) {
            foreach (['name', 'email', 'daily_token_limit'] as $field) {
                if (array_key_exists($field, $d) && $user->{$field} != $d[$field]) {
                    $user->{$field} = $d[$field];
                    $changed[] = $field;
                }
            }
            if (isset($d['role']) && $d['role'] !== $user->role->slug) {
                $user->role_id = Role::where('slug', $d['role'])->firstOrFail()->id;
                $changed[] = 'role';
            }
            $user->save();
            if (array_key_exists('department_ids', $d)) {
                $result = $user->departments()->sync($d['department_ids']);
                if (array_filter($result)) {
                    $changed[] = 'departments';
                }
            }
        });
        if ($changed) {
            Audit::log('account.updated', $request->user(), $user, ['fields' => $changed]);
        }

        return Api::ok(UserResource::make($user->fresh()->load('role', 'departments'))->resolve());
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage', $user);
        if ($user->is($request->user())) {
            return Api::error('cannot_modify_self', 'You cannot deactivate your own account.', 422);
        }
        if ($user->hasRole(Role::SUPER_ADMIN) && $this->isLastActiveSuperAdmin($user)) {
            return Api::error('last_super_admin', 'There must always be at least one active Super Admin.', 422);
        }

        // History is kept: the account is switched off and signed out, never deleted.
        $user->forceFill(['is_active' => false, 'deactivated_at' => now(), 'session_id' => null])->save();
        Audit::log('account.deactivated', $request->user(), $user);
        app(Tracker::class)->safely(fn (Tracker $t) => $t->cut($user, 'deactivated'));

        return Api::ok(UserResource::make($user->load('role', 'departments'))->resolve());
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage', $user);
        $user->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
        Audit::log('account.reactivated', $request->user(), $user);

        return Api::ok(UserResource::make($user->load('role', 'departments'))->resolve());
    }

    /** Privileged reset: sets a new password and signs the account out. Never returns the password. */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage', $user);
        $d = $request->validate(['password' => ['required', 'string', 'min:'.config('thryve.min_password_length'), 'max:255']]);

        $user->password = $d['password'];
        $user->session_id = null;
        $user->must_change_password = ! $user->is($request->user()); // someone setting their own password is not made to change it again
        $user->save();
        Audit::log('account.password_reset', $request->user(), $user);

        return Api::ok(['reset' => true]);
    }

    /**
     * Queue the invitation email for a new account. Returns what the admin should be told: 'queued', 'no_email'
     * (no address was entered), 'email_off' (email is not set up) or 'failed' (it could not be queued). The account
     * exists in every case.
     */
    private function sendInvitation(Request $request, User $user, string $startingPassword): string
    {
        if (! $user->email) {
            return 'no_email';
        }
        if (! MailConfigurator::enabled()) {
            return 'email_off';
        }
        try {
            SendInvitationEmail::dispatch($user->id, $request->user()->id, $startingPassword);
        } catch (Throwable $e) {
            report($e);

            return 'failed';
        }

        return 'queued';
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        return User::where('is_active', true)->where('id', '!=', $user->id)
            ->whereHas('role', fn ($r) => $r->where('slug', Role::SUPER_ADMIN))->doesntExist();
    }
}
