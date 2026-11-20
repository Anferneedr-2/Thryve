<?php

namespace App\Console\Commands;

use App\Support\ChatTransfer;
use Illuminate\Console\Command;

/** Writes one chatbot's conversations for chosen people into a transfer file, to be imported on another installation. */
class ChatsExport extends Command
{
    protected $signature = 'thryve:export-chats {--agent=general-ai-assistant : the chatbot\'s short name} {--users= : comma separated usernames (required)} {--out= : where to write the file}';

    protected $description = 'Export a chatbot\'s conversations (text and attached files, no keys or passwords) to a transfer file';

    public function handle(): int
    {
        $users = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('users')))));
        if (! $users) {
            $this->error('Say whose chats to export, for example --users=superadmin,Robi');

            return self::FAILURE;
        }
        $out = $this->option('out') ?: storage_path('app/transfer/general-chats.json');
        try {
            $bundle = ChatTransfer::export($this->option('agent'), $users);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        file_put_contents($out, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);

        $c = $bundle['counts'];
        $this->info("Wrote {$out}");
        $this->line("{$c['conversations']} conversations, {$c['messages']} messages, {$c['files']} attached files for: ".implode(', ', $users));
        $this->warn('This file contains private conversations. Copy it only to your own device and do not commit or share it.');

        return self::SUCCESS;
    }
}
