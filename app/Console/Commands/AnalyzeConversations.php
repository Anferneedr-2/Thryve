<?php

namespace App\Console\Commands;

use App\Analysis\ConversationAnalyzer;
use App\Support\Settings;
use App\Updates\Maintenance;
use Illuminate\Console\Command;

class AnalyzeConversations extends Command
{
    protected $signature = 'thryve:analyze-conversations {--limit= : Conversations to analyze this run (default: the "per run" setting)} {--seconds=45 : Stop starting new ones after this many seconds}';

    protected $description = 'Send finished conversations to the report agent and store task, topics, prompt scores and a tip';

    public function handle(ConversationAnalyzer $analyzer, Maintenance $maintenance): int
    {
        if ($maintenance->active()) {
            $this->warn('An update is running; analysis was skipped.');

            return self::SUCCESS;
        }
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : (int) Settings::get('analysis_batch_size');
        $r = $analyzer->run(max(1, $limit), max(1, (int) $this->option('seconds')));

        if ($r['status'] !== 'ran') {
            $this->line('Analysis is not running: '.match ($r['status']) {
                'off' => 'it is switched off, or there is no enabled report agent.',
                'no_topics' => 'there are no active topics.',
                default => $r['status'],
            });

            return self::SUCCESS;
        }
        $this->info("Analyzed {$r['valid']} conversation(s); {$r['unassessable']} could not be assessed, {$r['failed']} failed, {$r['stale']} changed meanwhile.".($r['stopped'] ? " Stopped early: {$r['stopped']}." : ''));

        return self::SUCCESS;
    }
}
