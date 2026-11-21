<?php

namespace App\Console\Commands;

use App\Updates\BackupService;
use Illuminate\Console\Command;

/** Lists backups, newest first, so an id can be given to thryve:restore (for example over SSH in an emergency). */
class BackupList extends Command
{
    protected $signature = 'thryve:backups';

    protected $description = 'List the backups on this server (newest first)';

    public function handle(BackupService $backups): int
    {
        $all = $backups->all();
        if (! $all) {
            $this->warn('There are no backups in '.$backups->root().'. If the off-server copy has some, copy a folder back first.');

            return self::SUCCESS;
        }
        $this->table(['Id', 'Created', 'Kind', 'Version', 'Off-server'], array_map(fn ($b) => [$b['id'], $b['created_at'], $b['kind'], $b['app_version'], $b['offsite'] ?? 'none'], $all));

        return self::SUCCESS;
    }
}
