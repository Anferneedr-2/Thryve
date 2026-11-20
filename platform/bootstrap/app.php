<?php

use App\Http\Responses\Api;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API callers never get a redirect; without this, a request lacking an Accept header crashed on a missing login route.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
        // Maintenance runs first, before sessions or the database are touched; its bypass cookie must stay readable as plain text.
        $middleware->prepend(\App\Http\Middleware\CheckMaintenance::class);
        $middleware->encryptCookies(except: [\App\Updates\Maintenance::COOKIE]);
        // Behind a tunnel/proxy (e.g. Cloudflare) the request arrives over http; trust X-Forwarded-Proto so URLs and assets stay https.
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'installed' => \App\Http\Middleware\EnsureInstalled::class,
            'not-installed' => \App\Http\Middleware\EnsureNotInstalled::class,
            'current-session' => \App\Http\Middleware\EnsureCurrentSession::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
            'policy' => \App\Http\Middleware\EnsurePolicyAcknowledged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // One error shape for every API failure; never leak exception internals.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? Api::error('validation_failed', 'The given data was invalid.', 422, $e->errors())
                : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? Api::error('unauthenticated', 'Authentication required.', 401) : null;
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($isApi) {
            return $isApi($request) ? Api::error('forbidden', 'You do not have permission to do this.', 403) : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi) {
            return $isApi($request) ? Api::error('not_found', 'Resource not found.', 404) : null;
        });
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = $status >= 500 ? 'Something went wrong.' : ($e->getMessage() ?: 'Request failed.');

            return Api::error($status >= 500 ? 'server_error' : 'http_error', $message, $status);
        });
    })->create();
