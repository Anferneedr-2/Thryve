<?php

namespace App\Http\Controllers;

use App\Attendance\Tracker;
use App\Http\Resources\UserResource;
use App\Http\Responses\Api;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Same message for every failure so the form cannot be used to discover accounts.
    private const FAILED = 'The username or password is incorrect.';

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::with('role', 'departments')->where('username', $data['username'])->first();
        // Always run one hash check so response time does not reveal whether the username exists.
        $passwordOk = Hash::check($data['password'], $user->password ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

        $reason = match (true) {
            ! $user => 'unknown_user',
            ! $passwordOk => 'bad_password',
            ! $user->is_active => 'inactive',
            default => null,
        };

        if ($reason) {
            // No account lockout (approved rule): failures are only recorded.
            Audit::log('auth.login_failed', $user?->is_active ? $user : null, $user, ['reason' => $reason, 'username' => mb_substr($data['username'], 0, 64)]);

            return Api::error('invalid_credentials', self::FAILED, 401);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate(); // new session id on login (fixation protection)
        $this->takeOver($user, $request->session()->getId());
        if (Hash::needsRehash($user->password)) {
            $user->password = $data['password'];
        }
        $user->forceFill(['last_login_at' => now()])->save();

        Audit::log('auth.login', $user, $user);
        app(Tracker::class)->safely(fn (Tracker $t) => $t->start($user, now()));

        return Api::ok($this->present($user));
    }

    public function me(Request $request): JsonResponse
    {
        return Api::ok($this->present($request->user()->load('role', 'departments')));
    }

    private function present(User $user): array
    {
        return UserResource::make($user)->resolve() + ['policy_acknowledged' => $user->hasAcknowledgedPolicy()];
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['session_id' => null])->save();
        Audit::log('auth.logout', $user, $user);
        app(Tracker::class)->safely(fn (Tracker $t) => $t->end($user, now(), 'logout'));

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Api::ok(['signed_out' => true]);
    }

    /**
     * One active session: the new login becomes the only valid one. Older sessions are not deleted here;
     * the current-session check rejects them with a clear message and removes them when they next call in.
     */
    private function takeOver(User $user, string $newSessionId): void
    {
        $user->forceFill(['session_id' => $newSessionId])->save();
    }
}
