<?php

namespace App\Console\Commands;

use App\Attendance\Tracker;
use Illuminate\Console\Command;

class ReconcileAttendance extends Command
{
    protected $signature = 'thryve:attendance-reconcile';

    protected $description = 'Close attendance sessions whose browser stopped reporting (closed tab, sleep, lost network)';

    public function handle(Tracker $tracker): int
    {
        $closed = $tracker->reconcile(now());
        $this->info("Closed {$closed} silent session(s).");

        return self::SUCCESS;
    }
}
