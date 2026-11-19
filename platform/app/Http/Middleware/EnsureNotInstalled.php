<?php

namespace App\Http\Middleware;

use App\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Installer routes disappear (404) once installation has finished, so they cannot be re-run. */
class EnsureNotInstalled
{
    public function __construct(private Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->installer->installed(), 404);

        return $next($request);
    }
}
