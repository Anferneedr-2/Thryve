<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Feature routes stay closed while the person is still using the password an admin gave them. */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return Api::error('password_change_required', 'Please choose your own password first.', 403);
        }

        return $next($request);
    }
}
