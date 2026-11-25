<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Things that belong to the employee alone (their password, their policy acceptance) stay closed while viewing as them. */
class RejectWhileImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::active($request)) {
            return Api::error('not_while_viewing_as', 'This is not available while you are viewing as someone else.', 403);
        }

        return $next($request);
    }
}
