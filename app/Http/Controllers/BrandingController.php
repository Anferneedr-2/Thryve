<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Support\Audit;
use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BrandingController extends Controller
{
    /** Public: the company name, theme default and whether a logo exists (needed on the sign-in page). */
    public function show(): JsonResponse
    {
        return Api::ok(Branding::summary());
    }

    /** Public image route used by the page and the browser tab icon. */
    public function file(string $kind): Response
    {
        abort_unless(in_array($kind, Branding::KINDS, true), 404);
        $path = Branding::find($kind);
        abort_unless($path, 404);

        return response(Branding::read($path), 200, [
            'Content-Type' => Branding::contentType($path),
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** The favicon as a square PNG for phone home screens and the web app manifest. */
    public function icon(string $size): Response
    {
        $png = Branding::icon((int) $size);
        abort_unless($png !== null, 404);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400', // addresses carry ?v=, so a new favicon gets new addresses
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** What a phone needs to offer "Add to home screen": the name, the colours and the icons made from the favicon. */
    public function manifest(): Response
    {
        try {
            $name = Settings::get('company_name') ?: config('app.name');
        } catch (\Throwable) {
            $name = config('app.name'); // not installed yet, or the database is unreachable
        }
        $v = Branding::faviconVersion();
        $icons = ($v !== null && Branding::faviconIsPng())
            ? array_map(fn ($s) => ['src' => "/branding/icon/{$s}?v={$v}", 'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => 'any'], [192, 512])
            : [];

        return response(json_encode([
            'name' => $name, 'short_name' => mb_substr($name, 0, 12), 'start_url' => '/', 'scope' => '/', 'display' => 'standalone',
            'background_color' => '#f8fafc', 'theme_color' => '#315eef', 'icons' => $icons,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=300']);
    }

    /** Super Admin only (route guard). */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['sometimes', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'logo_dark' => ['sometimes', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'logo_login' => ['sometimes', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
            'favicon' => ['sometimes', 'file', 'mimetypes:image/png,image/x-icon,image/vnd.microsoft.icon', 'max:256'],
        ]);
        $saved = [];
        foreach (Branding::KINDS as $kind) {
            if ($request->hasFile($kind)) {
                Branding::store($kind, $request->file($kind));
                $saved[] = $kind;
            }
        }
        if (! $saved) {
            return Api::error('validation_failed', 'Choose a logo or a favicon to upload.', 422, ['logo' => ['Choose a logo or a favicon to upload.']]);
        }
        Audit::log('settings.branding_updated', $request->user(), null, ['uploaded' => $saved]);

        return Api::ok(Branding::summary());
    }

    public function destroy(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, Branding::KINDS, true), 404);
        Branding::remove($kind);
        Audit::log('settings.branding_updated', $request->user(), null, ['removed' => [$kind]]);

        return Api::ok(Branding::summary());
    }
}
