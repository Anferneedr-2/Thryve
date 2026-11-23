<?php

use App\Updates\Maintenance;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// cPanel cron runs `php artisan schedule:run` every minute; this proves it is alive.
Schedule::command('thryve:heartbeat')->everyMinute()->skip(fn () => app(Maintenance::class)->active());

// Keep the single-session bookkeeping tidy.
Schedule::command('thryve:prune-sessions')->hourly()->skip(fn () => app(Maintenance::class)->active());

// Close attendance sessions whose browser went silent.
Schedule::command('thryve:attendance-reconcile')->everyMinute()->skip(fn () => app(Maintenance::class)->active());

// Process queued emails every minute. cPanel has no always-on worker, so each run drains the queue and exits.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping()->skip(fn () => app(Maintenance::class)->active());

// Background conversation analysis for the reports. Small batches every five minutes: the report agent shares the AI
// subscription with live chat, so this must never crowd employees out. It does nothing until a Super Admin switches it on.
Schedule::command('thryve:analyze-conversations')->everyFiveMinutes()->withoutOverlapping(10)->skip(fn () => app(Maintenance::class)->active());

// Daily backup of the database and uploaded files (Philippine time), then removal of old backups.
Schedule::command('thryve:backup')->dailyAt(config('thryve.backups.schedule_time'))->timezone(config('thryve.business_timezone'))->withoutOverlapping()->skip(fn () => app(Maintenance::class)->active());
