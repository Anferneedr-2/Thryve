<?php

namespace App\Updates;

use Illuminate\Support\Facades\Artisan;

/** The parts of an update that run application code. A separate class so rehearsals and tests can replace it. */
class UpdateSteps
{
    public function migrate(): void
    {
        $code = Artisan::call('migrate', ['--force' => true]);
        if ($code !== 0) {
            throw new \RuntimeException('The database migration did not finish.');
        }
    }

    /** Idempotent seeders only (role names and similar); safe to run on every update. */
    public function seed(): void
    {
        $code = Artisan::call('db:seed', ['--force' => true]);
        if ($code !== 0) {
            throw new \RuntimeException('Seeding did not finish.');
        }
    }

    public function clearCaches(): void
    {
        Artisan::call('optimize:clear');
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
