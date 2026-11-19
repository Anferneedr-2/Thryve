<?php

namespace App\Console\Commands;

use App\Updates\BackupService;
use Illuminate\Console\Command;

/** Command-line restore for emergencies (for example over SSH). Replaces the database and the backed-up files. */
class BackupRestore extends Command
{
    protected $signature = 'thryve:restore {id : Backup id, see thryve:backups} {--force : Do not ask for confirmation}';

    protected $description = 'Restore the database and files from a backup (destructive)';

    public function handle(BackupService $backups): int
    {
        $meta = $backups->find($this->argument('id'));
        if (! $meta) {
            $this->error('No backup with that id.');

            return self::FAILURE;
        }
        if ($problems = $backups->verify($meta)) {
            $this->error('The backup did not pass its check: '.implode(' ', $problems));

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("This replaces the CURRENT database and files with backup {$meta['id']} from {$meta['created_at']}. Continue?")) {
            return self::FAILURE;
        }
        $backups->restoreFiles($meta);
        $backups->restoreDatabase($meta);
        $this->info('Restored. Clear caches with: php artisan optimize:clear');

        return self::SUCCESS;
    }
}
