<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneSessions extends Command
{
    protected $signature = 'thryve:prune-sessions';

    protected $description = 'Delete expired session rows and clear single-session ids that no longer have a live session';

    public function handle(): int
    {
        if (config('session.driver') !== 'database') {
            return self::SUCCESS;
        }
        $table = config('session.table');

        $expired = DB::table($table)->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())->delete();
        $cleared = DB::table('users')->whereNotNull('session_id')
            ->whereNotIn('session_id', DB::table($table)->select('id'))->update(['session_id' => null]);

        $this->info("Removed {$expired} expired sessions, cleared {$cleared} stale user sessions.");

        return self::SUCCESS;
    }
}
