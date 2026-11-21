<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use App\Updates\Maintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an update runs, everyone sees a maintenance notice, except the Super Admin carrying the bypass cookie.
 * Runs before sessions and the database are touched, so it works even while the database is being restored.
 */
class CheckMaintenance
{
    public function __construct(private Maintenance $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $data = $this->maintenance->data();
        if ($data === null || $request->is('up') || $request->is('branding/*') || $this->maintenance->allows($request->cookie(Maintenance::COOKIE))) {
            return $next($request);
        }
        $message = $data['message'] ?? 'The platform is being updated. Please try again in a few minutes.';

        if ($request->is('api/*') || $request->expectsJson()) {
            return Api::error('maintenance', $message, 503)->header('Retry-After', '120');
        }

        return response()->view('maintenance', ['message' => $message, 'since' => $data['since'] ?? null], 503)->header('Retry-After', '120');
    }
}
