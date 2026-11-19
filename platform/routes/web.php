<?php

use App\Http\Controllers\BrandingController;
use App\Install\Installer;
use App\Support\Branding;
use Illuminate\Support\Facades\Route;

// Company logo and favicon (public, served from private storage).
Route::get('/branding/{kind}', [BrandingController::class, 'file'])->where('kind', 'logo|favicon');

// Single-page React shell: every non-API path is served the same view.
Route::get('/{any?}', function (Installer $installer) {
    $installed = $installer->installed();

    return view('app', ['installed' => $installed, 'branding' => $installed ? Branding::summary() : null]);
})->where('any', '^(?!api).*$');
