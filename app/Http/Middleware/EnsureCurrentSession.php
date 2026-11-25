<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use App\Models\Role;
use App\Models\User;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after 'auth'. Rejects a session that is no longer the account's single active session,
 * was terminated by a Super Admin, or belongs to a deactivated account.
 *
 * While a Super Admin is viewing as an employee, this browser holds the Super Admin's single session, not the employee's:
 * the Super Admin's own session is what is checked, and the employee must still be active.
 */
class EnsureCurrentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $impersonatorId = Impersonation::impersonatorId($request);
        $owner = $impersonatorId ? User::find($impersonatorId) : $user; // whose single session this browser holds

        $reason = match (true) {
            ! $user->is_active => ['account_deactivated', 'This account has been deactivated.'],
            ! $owner || ! $owner->is_active || ($impersonatorId && ! $owner->hasRole(Role::SUPER_ADMIN)) => ['account_deactivated', 'This account has been deactivated.'],
            $owner->session_id === null => ['session_terminated', 'Your session was ended by an administrator. Please sign in again.'],
            $owner->session_id !== $request->session()->getId() => ['session_replaced', 'You were signed out because this account signed in somewhere else.'],
            default => null,
        };

        if ($reason) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Api::error($reason[0], $reason[1], 401);
        }

        return $next($request);
    }
}
