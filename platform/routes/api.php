<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AttendanceAdminController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PriceController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\SessionAdminController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\UserController;
use App\Http\Responses\Api;
use Illuminate\Support\Facades\Route;

// Public: liveness for the SPA/installer. No secrets or dependency details.
Route::get('/health', fn () => Api::ok(['status' => 'ok']));

// Installer: exists only until installation finishes (404 afterwards).
Route::prefix('install')->middleware(['not-installed', 'throttle:60,1'])->group(function () {
    Route::get('/checks', [InstallController::class, 'checks']);
    Route::post('/test-database', [InstallController::class, 'testDatabase']);
    Route::post('/test-hermes', [InstallController::class, 'testHermes']);
    Route::post('/run', [InstallController::class, 'run']);
});

// Everything else needs a finished installation and uses the cookie session ('web' group = session + CSRF).
Route::middleware(['web', 'installed'])->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::get('/branding', [BrandingController::class, 'show']);

    // Authenticated boundary: signed in, active, and still the account's single current session.
    Route::middleware(['auth', 'current-session'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::post('/account/password', [AccountController::class, 'changePassword']);
        Route::get('/policy', [PolicyController::class, 'show']);
        Route::post('/policy/acknowledge', [PolicyController::class, 'acknowledge']);

        // Everything below needs the current usage policy to be acknowledged first.
        Route::middleware('policy')->group(function () {

        // Own notifications (the bell). Scoped to the signed-in user in the controller.
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');

        // Own conversations (private to the signed-in person).
        Route::get('/conversations', [\App\Http\Controllers\ChatController::class, 'index']);
        Route::post('/chat', [\App\Http\Controllers\ChatController::class, 'send'])->middleware('throttle:30,1');
        Route::get('/conversations/{id}', [\App\Http\Controllers\ChatController::class, 'show'])->whereNumber('id');
        Route::patch('/conversations/{id}', [\App\Http\Controllers\ChatController::class, 'update'])->whereNumber('id');
        Route::delete('/conversations/{id}', [\App\Http\Controllers\ChatController::class, 'destroy'])->whereNumber('id');
        Route::post('/conversations/{id}/regenerate', [\App\Http\Controllers\ChatController::class, 'regenerate'])->whereNumber('id')->middleware('throttle:30,1');
        Route::patch('/conversations/{id}/messages/{messageId}', [\App\Http\Controllers\ChatController::class, 'edit'])->whereNumber(['id', 'messageId'])->middleware('throttle:30,1');
        Route::post('/conversations/{id}/messages/{messageId}/rating', [\App\Http\Controllers\ChatController::class, 'rate'])->whereNumber(['id', 'messageId']);

        // Own token allowance (never any provider detail).
        Route::get('/usage/today', [UsageController::class, 'mine']);

        // Own attendance (never anyone else's).
        Route::post('/attendance/heartbeat', [AttendanceController::class, 'heartbeat'])->middleware('throttle:20,1');
        Route::post('/attendance/close', [AttendanceController::class, 'close']);
        Route::get('/attendance/me', [AttendanceController::class, 'mine']);

        // Account management: Admin and Super Admin; finer limits (who may touch whom) live in UserPolicy.
        Route::middleware('role:admin,super_admin')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::get('/users/{user}', [UserController::class, 'show']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);

            // Providers and usage: Admins monitor (read-only); only Super Admin changes anything (group below).
            Route::get('/providers', [ProviderController::class, 'index']);
            Route::get('/prices', [PriceController::class, 'index']);
            Route::get('/usage/summary', [UsageController::class, 'summary']);
            Route::post('/users/{user}/token-grants', [UsageController::class, 'grant']);

            Route::get('/attendance', [AttendanceAdminController::class, 'index']);
            Route::post('/attendance/corrections', [AttendanceAdminController::class, 'correct']);
            Route::get('/attendance/days/{day}/corrections', [AttendanceAdminController::class, 'history']);

            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
            Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
        });

        Route::post('/users/{user}/terminate-session', [SessionAdminController::class, 'terminate'])->middleware('role:super_admin');

        Route::middleware('role:super_admin')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::patch('/settings', [SettingsController::class, 'update']);
            Route::post('/settings/smtp-test', [SettingsController::class, 'smtpTest'])->middleware('throttle:10,1');
            Route::post('/settings/branding', [BrandingController::class, 'upload']);
            Route::delete('/settings/branding/{kind}', [BrandingController::class, 'destroy']);
            Route::post('/providers', [ProviderController::class, 'store']);
            Route::post('/providers/reorder', [ProviderController::class, 'reorder']);
            Route::patch('/providers/{provider}', [ProviderController::class, 'update']);
            Route::post('/providers/{provider}/reauthenticate', [ProviderController::class, 'reauthenticate']);
            Route::post('/providers/{provider}/check', [ProviderController::class, 'check'])->middleware('throttle:20,1');
            Route::post('/providers/{provider}/quota', [ProviderController::class, 'quota']);
            Route::delete('/providers/{provider}', [ProviderController::class, 'destroy']);
            Route::post('/prices', [PriceController::class, 'store']);

            Route::get('/system/health', [SystemController::class, 'health']);

            // Updates and backups (maintenance mode lets this browser keep working while others see the notice).
            Route::get('/system/updates', [UpdateController::class, 'status']);
            Route::post('/system/updates/stage', [UpdateController::class, 'stage'])->middleware('throttle:10,1');
            Route::post('/system/updates/approve', [UpdateController::class, 'approve']);
            Route::post('/system/updates/advance', [UpdateController::class, 'advance']);
            Route::post('/system/updates/rollback', [UpdateController::class, 'rollback']);
            Route::delete('/system/updates/staged', [UpdateController::class, 'discard']);
            Route::get('/system/backups', [UpdateController::class, 'backups']);
            Route::post('/system/backups', [UpdateController::class, 'backupNow'])->middleware('throttle:6,1');
            Route::get('/system/backups/{id}/verify', [UpdateController::class, 'verifyBackup']);
        });

        // Feature routes are added here by their owning phase.

        }); // policy
    });
});
