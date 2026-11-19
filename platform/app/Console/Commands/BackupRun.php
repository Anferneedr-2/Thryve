<?php

namespace App\Console\Commands;

use App\Updates\BackupService;
use App\Updates\Maintenance;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature = 'thryve:backup';

    protected $description = 'Back up the database and uploaded files, copy off-server if configured, and remove old backups';

    public function handle(BackupService $backups, Maintenance $maintenance): int
    {
        if ($maintenance->active()) {
            $this->warn('An update is running; the backup was skipped.');

            return self::SUCCESS;
        }
        $meta = $backups->create('Scheduled backup', 'scheduled', [], ['storage/app/private/files', 'storage/app/private/branding']);
        $problems = $backups->verify($meta);
        if ($problems) {
            $this->error('The backup did not pass its check: '.implode(' ', $problems));

            return self::FAILURE;
        }
        $offsite = $backups->copyOffsite($meta);
        $removed = $backups->prune();
        $this->info("Backup {$meta['id']} created (off-server copy: {$offsite}). Removed {$removed} old backup(s).");

        return self::SUCCESS;
    }
}
