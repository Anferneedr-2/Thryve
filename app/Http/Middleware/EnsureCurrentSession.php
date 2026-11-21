<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after 'auth'. Rejects a session that is no longer the account's single active session,
 * was terminated by a Super Admin, or belongs to a deactivated account.
 */
class EnsureCurrentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $reason = match (true) {
            ! $user->is_active => ['account_deactivated', 'This account has been deactivated.'],
            $user->session_id === null => ['session_terminated', 'Your session was ended by an administrator. Please sign in again.'],
            $user->session_id !== $request->session()->getId() => ['session_replaced', 'You were signed out because this account signed in somewhere else.'],
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
