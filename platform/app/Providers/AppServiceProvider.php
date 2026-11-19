<?php

namespace App\Providers;

use App\Install\Installer;
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
    }
}
