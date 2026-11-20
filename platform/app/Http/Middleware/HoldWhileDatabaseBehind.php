<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use App\Updates\UpdateManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After new code is deployed (for example by a GitHub Action) the database may be behind it: the code can expect tables or
 * columns that do not exist yet. Until a Super Admin runs the database update, everyone else is held out with the same
 * maintenance notice an update shows. The Super Admin gets through to run the update (Settings > Updates and backups).
 */
class HoldWhileDatabaseBehind
{
    public function __construct(private UpdateManager $updates) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->hasRole('super_admin') && ! $request->is('api/auth/logout') && $this->updates->databaseBehind()) {
            return Api::error('maintenance', 'The platform is being updated. Please try again in a few minutes.', 503)->header('Retry-After', '120');
        }

        return $next($request);
    }
}
