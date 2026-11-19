<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Support\Audit;
use App\Support\Branding;
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

    /** Super Admin only (route guard). */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['sometimes', 'file', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
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
