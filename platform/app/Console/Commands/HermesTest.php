<?php

namespace App\Console\Commands;

use App\Ai\HermesClient;
use App\Ai\NoProviderAvailable;
use App\Ai\ProviderException;
use App\Ai\ProviderRouter;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sends one tiny message to Hermes through the real router and ledger, so connection, key, model name,
 * token counting and failure handling can be checked end to end. Costs one small request (and Hermes adds its own
 * system prompt, so expect thousands of input tokens even for "ping"). Recorded as background "analysis" usage.
 */
class HermesTest extends Command
{
    protected $signature = 'thryve:hermes-test {message=Reply with the single word: pong}';

    protected $description = 'Send one small message to Hermes through the router and show the result';

    public function handle(HermesClient $hermes, ProviderRouter $router): int
    {
        try {
            $out = $router->run(
                null,
                'smoke-'.Str::lower(Str::random(10)),
                (string) Settings::get('hermes_model_label'),
                fn ($connection) => $hermes->chat([['role' => 'user', 'content' => $this->argument('message')]], $connection),
                'analysis',
            );
        } catch (NoProviderAvailable $e) {
            $this->error('No provider connection is available. Add one on the Providers page first.');

            return self::FAILURE;
        } catch (ProviderException $e) {
            $this->error("Hermes call failed: {$e->category}".($e->ambiguous ? ' (outcome unknown, not retried elsewhere)' : ''));

            return self::FAILURE;
        }

        $r = $out['result'];
        $this->info('Answer: '.mb_substr($r['content'], 0, 300));
        $this->line(sprintf('Tokens: %d in, %d out%s', $r['input'], $r['output'], $r['estimate'] ? ' (estimated)' : ''));
        $this->line("Connection: {$out['connection']->name}");

        return self::SUCCESS;
    }
}
