<?php

namespace App\Providers;

use App\Install\Installer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Installer::class);
    }

    public function boot(): void
    {
        // Before installation there is no database, so sessions and cache must not use it.
        if (! $this->app->runningUnitTests() && ! $this->app->make(Installer::class)->installed()) {
            config(['session.driver' => 'file', 'cache.default' => 'file']);
        }

        // Own counters, so other throttled calls (like the attendance heartbeat) never use up these small allowances.
        RateLimiter::for('transfer-chats', fn (Request $r) => Limit::perMinute(20)->by('transfer-chats:'.($r->user()?->id ?? $r->ip())));
        RateLimiter::for('transfer-key', fn (Request $r) => Limit::perMinute(5)->by('transfer-key:'.($r->user()?->id ?? $r->ip())));
    }
}
