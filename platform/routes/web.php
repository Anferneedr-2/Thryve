<?php

use App\Http\Controllers\BrandingController;
use App\Install\Installer;
use App\Support\Branding;
use Illuminate\Support\Facades\Route;

// Company logo and favicon (public, served from private storage).
Route::get('/branding/{kind}', [BrandingController::class, 'file'])->where('kind', 'logo|logo_dark|logo_login|favicon');

// App icons made from the favicon, and the web app manifest that points at them.
Route::get('/branding/icon/{size}', [BrandingController::class, 'icon']); // any size is asked here; only the listed ones exist (404 for the rest, not the app page)
Route::get('/manifest.webmanifest', [BrandingController::class, 'manifest']);

// Single-page React shell: every non-API path is served the same view.
Route::get('/{any?}', function (Installer $installer) {
    $installed = $installer->installed();

    return view('app', ['installed' => $installed, 'branding' => $installed ? Branding::summary() : null]);
})->where('any', '^(?!api).*$');
