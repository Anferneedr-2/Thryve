<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeat extends Command
{
    protected $signature = 'thryve:heartbeat';

    protected $description = 'Record that the scheduler (cPanel cron) is running';

    public function handle(): int
    {
        Cache::forever('scheduler:last_run', now()->toIso8601String());

        return self::SUCCESS;
    }
}
