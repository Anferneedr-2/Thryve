<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Support\AgentKeyTransfer;
use App\Support\Audit;
use Illuminate\Console\Command;

/** Sets a chatbot's access key from a file made by thryve:export-agent-key, after you type its passphrase. */
class AgentKeyImport extends Command
{
    protected $signature = 'thryve:import-agent-key {file? : the key file (default: storage/app/transfer/general-ai-assistant.key.json)} {--replace : replace a different key the chatbot already has}';

    protected $description = 'Set a chatbot\'s access key from a passphrase-protected key file';

    public function handle(): int
    {
        $file = $this->argument('file') ?: storage_path('app/transfer/general-ai-assistant.key.json');
        if (! is_file($file)) {
            $this->error("No key file at {$file}.");

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            $this->error('That file is not readable as a key file.');

            return self::FAILURE;
        }
        // Check the chatbot exists before asking for a passphrase.
        $agent = Agent::active()->where('slug', (string) ($data['agent'] ?? ''))->first();
        if (! $agent) {
            $this->error('This chatbot is not here yet. Import the chats first (php artisan thryve:import-chats): that creates it. Then run this again.');

            return self::FAILURE;
        }
        if ($agent->outbound_key && $agent->outbound_fingerprint !== ($data['fingerprint'] ?? null) && ! $this->option('replace')) {
            $this->error("{$agent->name} already has a different key (ends in {$agent->outbound_fingerprint}). Run again with --replace if you really want to swap it.");

            return self::FAILURE;
        }

        try {
            $opened = AgentKeyTransfer::open($data, (string) $this->secret('Passphrase'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($agent->outbound_key && $agent->outbound_fingerprint === $opened['fingerprint']) {
            $this->info("{$agent->name} already has this key (ends in {$opened['fingerprint']}). Nothing changed.");

            return self::SUCCESS;
        }
        $agent->update([
            'outbound_key' => $opened['key'], 'outbound_fingerprint' => $opened['fingerprint'], 'pending_key' => null, 'pending_fingerprint' => null,
            'status' => 'never_tested', 'last_checked_at' => null, 'last_latency_ms' => null,
        ]);
        Audit::log('agent.key_imported', null, null, ['agent_id' => $agent->id, 'fingerprint' => $opened['fingerprint']]); // the fingerprint only, never the key

        $this->info("Key set for {$agent->name}. It ends in {$opened['fingerprint']}: that should match the other device.");
        $this->line('Open Agents and press Test connection. The server must already accept this key; it does if it is the key this chatbot has been using.');

        return self::SUCCESS;
    }
}
