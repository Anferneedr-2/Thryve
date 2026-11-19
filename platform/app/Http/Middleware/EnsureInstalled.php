<?php

namespace App\Http\Middleware;

use App\Http\Responses\Api;
use App\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API routes other than the installer and health check need a finished installation. */
class EnsureInstalled
{
    public function __construct(private Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installer->installed()) {
            return Api::error('not_installed', 'The application has not been installed yet.', 503);
        }

        return $next($request);
    }
}
