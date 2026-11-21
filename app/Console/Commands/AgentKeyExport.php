<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Support\AgentKeyTransfer;
use Illuminate\Console\Command;

/**
 * Writes a chatbot's access key to a file protected by a passphrase you type here. The key is never shown, printed or logged,
 * and it is not part of the chat transfer file.
 */
class AgentKeyExport extends Command
{
    protected $signature = 'thryve:export-agent-key {--agent=general-ai-assistant : the chatbot\'s short name} {--out= : where to write the file}';

    protected $description = 'Write a chatbot\'s access key to a passphrase-protected file, to bring it to another device';

    public function handle(): int
    {
        $agent = Agent::active()->where('slug', $this->option('agent'))->first();
        if (! $agent) {
            $this->error('There is no chatbot with that name here.');

            return self::FAILURE;
        }
        $key = $agent->outbound_key;
        if (! $key) {
            $this->error("{$agent->name} has no access key here, so there is nothing to export.");

            return self::FAILURE;
        }

        $this->line('The key will be protected by a passphrase that only you know. Without it the file is useless, so keep it somewhere safe:');
        $this->line('it is not stored anywhere and cannot be recovered. Use at least 10 characters.');
        $pass = (string) $this->secret('Choose a passphrase');
        if (mb_strlen($pass) < 10) {
            $this->error('That passphrase is too short. Use at least 10 characters.');

            return self::FAILURE;
        }
        if ($pass !== (string) $this->secret('Type the passphrase again')) {
            $this->error('The two passphrases are not the same. Nothing was written.');

            return self::FAILURE;
        }

        $out = $this->option('out') ?: storage_path("app/transfer/{$agent->slug}.key.json");
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        file_put_contents($out, json_encode(AgentKeyTransfer::seal($agent->slug, $key, $pass), JSON_PRETTY_PRINT), LOCK_EX);

        $this->info("Wrote {$out}");
        $this->line("Key ends in {$agent->outbound_fingerprint}. After importing, the other device shows the same ending: compare them.");
        $this->warn('Copy the file to your other device yourself and send the passphrase by a different route than the file.');

        return self::SUCCESS;
    }
}
