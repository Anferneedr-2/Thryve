<?php

namespace App\Console\Commands;

use App\Support\ChatTransfer;
use Illuminate\Console\Command;

/** Reads a transfer file made by thryve:export-chats. Safe to run again: conversations already here are left alone. */
class ChatsImport extends Command
{
    protected $signature = 'thryve:import-chats {file? : the transfer file (default: storage/app/transfer/general-chats.json)} {--dry-run : show what would happen without changing anything}';

    protected $description = 'Import conversations from a transfer file (people are matched by username; nobody is created)';

    public function handle(): int
    {
        $file = $this->argument('file') ?: storage_path('app/transfer/general-chats.json');
        if (! is_file($file)) {
            $this->error("No transfer file at {$file}.");

            return self::FAILURE;
        }
        $bundle = json_decode((string) file_get_contents($file), true);
        if (! is_array($bundle)) {
            $this->error('That file is not readable as a transfer file.');

            return self::FAILURE;
        }
        try {
            $r = ChatTransfer::import($bundle, (bool) $this->option('dry-run'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($r['dry_run'] ? 'DRY RUN, nothing was changed. ' : '').'Chatbot: '.$r['agent']);
        $this->info(($r['dry_run'] ? 'Would import ' : 'Imported ')."{$r['imported']} conversations ({$r['messages']} messages, {$r['files']} attached files).");
        if ($r['duplicates']) {
            $this->line("Already here, left alone: {$r['duplicates']} conversations.");
        }
        if ($r['missing_users']) {
            $this->warn('Skipped because these people do not exist here (create them, then run this again): '.collect($r['missing_users'])->map(fn ($n, $u) => "{$u} ({$n})")->implode(', '));
        }
        if ($r['missing_files']) {
            $this->warn("{$r['missing_files']} attached files were missing in the transfer file and were not brought across.");
        }

        return self::SUCCESS;
    }
}
