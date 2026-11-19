<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Feature routes stay closed until the person has acknowledged the current policy version. */
class EnsurePolicyAcknowledged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasAcknowledgedPolicy()) {
            return Api::error('policy_not_acknowledged', 'Please read and acknowledge the usage policy first.', 403);
        }

        return $next($request);
    }
}
