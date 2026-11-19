<?php

namespace App\Console\Commands;

use App\Ai\AgentService;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * One-time move from the old HERMES_URL / HERMES_API_KEY settings in .env to a registered agent, keeping the key you
 * already installed on the VPS. After this, chat uses the agent and the .env values are no longer read.
 */
class AgentImport extends Command
{
    protected $signature = 'thryve:agent-import {name=General Assistant}';

    protected $description = 'Register the Hermes server from .env as the first agent (keeps its existing key)';

    public function handle(): int
    {
        if (Agent::active()->exists()) {
            $this->error('An agent already exists.');

            return self::FAILURE;
        }
        $url = (string) config('services.hermes.url');
        $key = (string) config('services.hermes.key');
        if ($url === '' || $key === '') {
            $this->error('HERMES_URL and HERMES_API_KEY are not both set in .env.');

            return self::FAILURE;
        }
        $profile = null;
        if (preg_match('#^(.*)/p/([a-z0-9][a-z0-9_-]*)/?$#', $url, $m)) {
            [$url, $profile] = [$m[1], $m[2]];
        }
        $admin = User::whereHas('role', fn ($r) => $r->where('slug', 'super_admin'))->orderBy('id')->first();
        $made = app(AgentService::class)->create($admin, ['name' => $this->argument('name'), 'base_url' => $url, 'profile' => $profile]);
        $agent = $made['agent'];
        $agent->update(['outbound_key' => $key, 'outbound_fingerprint' => AgentService::fingerprint($key)]);
        $this->info("Registered \"{$agent->name}\" at {$agent->url()} with the existing key (fingerprint {$agent->outbound_fingerprint}).");
        $this->line('Open AI agents and press Test to confirm.');

        return self::SUCCESS;
    }
}
