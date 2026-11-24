<?php

namespace Tests\Feature;

use App\Ai\AgentService;
use App\Ai\Allowance;
use App\Analysis\AnalysisContract;
use App\Analysis\ConversationAnalyzer;
use App\Models\Agent;
use App\Models\AnalysisTopic;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\Message;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/** The background job that sends finished conversations to the report agent and stores what comes back. */
class ConversationAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $super;

    private Agent $report;

    /** @var list<array> the request bodies the report agent received */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config(['services.hermes.url' => null, 'services.hermes.key' => null]);
        $this->super = User::factory()->role(Role::SUPER_ADMIN)->create();
        $this->owner = User::factory()->role(Role::EMPLOYEE)->create(['daily_token_limit' => 100000]);
        $this->report = Agent::create(['name' => 'Report AI', 'slug' => 'report-ai', 'type' => 'report', 'base_url' => 'https://report.example.com', 'profile' => 'report-ai',
            'outbound_key' => 'key-report-1234567890', 'outbound_fingerprint' => 'abcd1234']);
        Settings::update(['analysis_enabled' => true], $this->super);
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function conversation(array $employeeTexts = ['Draft a follow-up email to our client about the Q3 delay, polite tone, under 150 words.'], array $attrs = []): Conversation
    {
        $c = Conversation::create($attrs + ['user_id' => $this->owner->id, 'title' => 'Chat', 'last_message_at' => now()->subHour()]);
        foreach ($employeeTexts as $i => $text) {
            $this->message($c, 'user', $text);
            $this->message($c, 'assistant', "Reply {$i}");
        }

        return $c;
    }

    private function message(Conversation $c, string $role, string $content, array $attrs = []): Message
    {
        return Message::create($attrs + ['conversation_id' => $c->id, 'role' => $role, 'content' => $content, 'status' => 'ok']);
    }

    /** What the report agent answers with right now: 200 = correctly, anything else = that HTTP error. A test can change it between steps. */
    private int $agentStatus = 200;

    /** Make the report agent answer every request correctly (or the way $reply says), and remember what it was sent. */
    private function agentAnswers(?\Closure $reply = null, int $status = 200): void
    {
        $this->agentStatus = $status;
        Http::fake(function (Request $request) use ($reply) {
            if ($request->method() === 'GET') { // the free connection test lists the profiles on the server
                return $this->agentStatus === 200 ? Http::response(['data' => [['id' => 'report-ai']]]) : Http::response('', $this->agentStatus);
            }
            $body = $request->data();
            $this->sent[] = $body;
            if ($this->agentStatus !== 200) {
                return Http::response('', $this->agentStatus);
            }
            $payload = json_decode($body['messages'][0]['content'], true);
            $content = $reply ? $reply($payload) : json_encode([
                'request_ref' => $payload['request_ref'], 'rubric_version' => 'v1', 'task_label' => 'Draft client follow-up email',
                'topic_ids' => [$payload['topics'][0]['id']], 'scores' => ['clarity' => 4, 'context' => 3, 'specifics' => 5, 'refinement' => 2, 'verification' => 1], 'tip' => 'Ask for sources next time.',
            ]);

            return Http::response(['model' => 'report-ai', 'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 60]]);
        });
    }

    private function analyze(int $batch = 10): array
    {
        return app(ConversationAnalyzer::class)->run($batch);
    }

    // ---- storing a result ------------------------------------------------------------------------

    public function test_a_finished_conversation_is_analyzed_and_stored(): void
    {
        $this->agentAnswers();
        $c = $this->conversation(['First question', 'Please make it shorter']);

        $r = $this->analyze();

        $this->assertSame(1, $r['valid']);
        $a = ConversationAnalysis::sole();
        $this->assertSame([$c->id, $this->owner->id, $this->report->id, 'valid', 'v1'], [$a->conversation_id, $a->user_id, $a->agent_id, $a->status, $a->rubric_version]);
        $this->assertSame('Draft client follow-up email', $a->task_label);
        $this->assertSame('Ask for sources next time.', $a->tip);
        $this->assertSame([4, 3, 5, 2, 1], [$a->clarity, $a->context, $a->specifics, $a->refinement, $a->verification]);
        $this->assertSame([5, 3.0], [$a->dimensions_scored, $a->average]); // (4+3+5+2+1)/5
        $this->assertSame(2, $a->employee_message_count);
        $this->assertSame(4, $a->message_count);
        $this->assertSame(AnalysisTopic::active()->ordered()->first()->id, $a->topic_ids[0]);
        $this->assertNotNull($a->analyzed_at);
    }

    public function test_a_single_message_conversation_leaves_refinement_out_of_the_average(): void
    {
        $this->agentAnswers();
        $this->conversation(['Only one question']);

        $this->analyze();

        $a = ConversationAnalysis::sole();
        $this->assertNull($a->refinement);
        $this->assertSame([4, 3.25], [$a->dimensions_scored, $a->average]); // (4+3+5+1)/4
    }

    public function test_the_cost_goes_to_the_system_allowance_and_never_to_the_employee(): void
    {
        $this->agentAnswers();
        $c = $this->conversation();

        $this->analyze();

        $row = UsageRecord::sole();
        $this->assertSame(['analysis', 'success', $this->report->id, $c->id, 400, 60], [$row->purpose, $row->status, $row->agent_id, $row->conversation_id, $row->input_tokens, $row->output_tokens]);
        $this->assertSame($this->super->id, $row->user_id); // the technical placeholder, never the employee
        $this->assertSame(0, (int) UsageRecord::where('user_id', $this->owner->id)->sum('input_tokens'));
        $this->assertSame(0, app(Allowance::class)->summary($this->owner, BusinessDay::today())['used']);
    }

    public function test_the_request_has_the_conversation_the_topics_and_file_names_but_never_file_contents(): void
    {
        $this->agentAnswers();
        $c = $this->conversation(['Summarize the attached sheet']);
        $employeeMessage = Message::where('conversation_id', $c->id)->where('role', 'user')->first();
        Attachment::create(['user_id' => $this->owner->id, 'conversation_id' => $c->id, 'message_id' => $employeeMessage->id, 'original_name' => 'q3-sales.xlsx', 'kind' => 'xlsx',
            'size' => 10, 'storage_key' => 'k-1', 'sha256' => str_repeat('a', 64), 'extracted_text' => 'CONFIDENTIAL CELL VALUES', 'truncated' => false, 'generated' => false]);

        $this->analyze();

        $json = $this->sent[0]['messages'][0]['content'];
        $req = json_decode($json, true);
        $this->assertCount(1, $this->sent[0]['messages']); // one message, no history, no session
        $this->assertSame('analyze_conversation', $req['task']);
        $this->assertFalse($req['include_reasons']);
        $this->assertCount(11, $req['topics']);
        $this->assertSame('Summarize the attached sheet', $req['conversation']['messages'][0]['text']);
        $this->assertSame([['name' => 'q3-sales.xlsx', 'type' => 'xlsx']], $req['conversation']['messages'][0]['files']);
        $this->assertStringNotContainsString('CONFIDENTIAL', $json);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://report.example.com/p/report-ai/v1/chat/completions' && ! $r->hasHeader('X-Hermes-Session-Id'));
    }

    public function test_replaced_failed_and_empty_messages_are_not_sent(): void
    {
        $this->agentAnswers();
        $c = $this->conversation(['Real question']);
        $this->message($c, 'user', 'Old wording', ['superseded_at' => now()]);
        $this->message($c, 'assistant', 'A failed attempt', ['status' => 'failed']);
        $this->message($c, 'assistant', '');

        $this->analyze();

        $texts = array_column(json_decode($this->sent[0]['messages'][0]['content'], true)['conversation']['messages'], 'text');
        $this->assertSame(['Real question', 'Reply 0'], $texts);
    }

    // ---- which conversations ---------------------------------------------------------------------

    public function test_only_finished_undeleted_conversations_with_an_employee_message_are_analyzed(): void
    {
        $this->agentAnswers();
        $due = $this->conversation();
        $this->conversation(attrs: ['last_message_at' => now()->subMinutes(5)]); // still active: under the 15 minute delay
        $this->conversation(attrs: ['deleted_at' => now()]); // deleted by its owner
        $this->conversation(attrs: ['last_message_at' => null]);
        $empty = Conversation::create(['user_id' => $this->owner->id, 'title' => 'Empty', 'last_message_at' => now()->subHour()]);
        $this->message($empty, 'assistant', 'Hi, I am here'); // nothing from the employee

        $r = $this->analyze();

        $this->assertSame(1, $r['valid']);
        $this->assertSame([$due->id], ConversationAnalysis::pluck('conversation_id')->all());
        $this->assertCount(1, $this->sent);
    }

    public function test_archived_and_old_conversations_are_analyzed_too(): void
    {
        $this->agentAnswers();
        $archived = $this->conversation(attrs: ['archived_at' => now()->subDay()]);
        $old = $this->conversation(attrs: ['last_message_at' => now()->subMonths(3)]);

        $this->assertSame(2, $this->analyze()['valid']);
        $this->assertEqualsCanonicalizing([$archived->id, $old->id], ConversationAnalysis::pluck('conversation_id')->all());
    }

    public function test_newest_conversations_come_first_and_the_batch_size_is_respected(): void
    {
        $this->agentAnswers();
        $oldest = $this->conversation(attrs: ['last_message_at' => now()->subDays(3)]);
        $middle = $this->conversation(attrs: ['last_message_at' => now()->subDays(2)]);
        $newest = $this->conversation(attrs: ['last_message_at' => now()->subDay()]);

        $r = $this->analyze(2);

        $this->assertSame(2, $r['valid']);
        $this->assertEqualsCanonicalizing([$newest->id, $middle->id], ConversationAnalysis::pluck('conversation_id')->all());
        $this->assertSame(0, ConversationAnalysis::where('conversation_id', $oldest->id)->count());
    }

    public function test_nothing_runs_when_it_is_switched_off_or_there_is_no_usable_report_agent(): void
    {
        $this->agentAnswers();
        $this->conversation();

        Settings::update(['analysis_enabled' => false], $this->super);
        $this->assertSame('off', $this->analyze()['status']);

        Settings::update(['analysis_enabled' => true], $this->super);
        $this->report->update(['is_enabled' => false]);
        $this->assertSame('off', $this->analyze()['status']);

        $this->report->update(['is_enabled' => true, 'outbound_key' => null]);
        $this->assertSame('off', $this->analyze()['status']);

        $this->report->update(['outbound_key' => 'key-report-1234567890', 'removed_at' => now()]);
        $this->assertSame('off', $this->analyze()['status']);

        $this->assertSame([], $this->sent);
        $this->assertSame(0, ConversationAnalysis::count());
    }

    public function test_a_chat_agent_is_never_used_for_analysis(): void
    {
        $this->agentAnswers();
        $this->report->delete();
        Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://general.example.com', 'profile' => 'general', 'is_default' => true, 'outbound_key' => 'key-general-1234567890']);
        $this->conversation();

        $this->assertSame('off', $this->analyze()['status']);
        $this->assertSame([], $this->sent);
    }

    public function test_it_does_not_run_without_active_topics(): void
    {
        $this->agentAnswers();
        $this->conversation();
        AnalysisTopic::query()->update(['is_active' => false]);

        $this->assertSame('no_topics', $this->analyze()['status']);
        $this->assertSame([], $this->sent);
    }

    // ---- never twice, always again when it changes -----------------------------------------------

    public function test_an_analyzed_conversation_is_not_sent_again_until_it_changes(): void
    {
        $this->agentAnswers();
        $c = $this->conversation();

        $this->analyze();
        $this->analyze();
        $this->assertCount(1, $this->sent);

        $this->travel(2)->hours();
        $this->message($c, 'user', 'One more thing');
        $c->update(['last_message_at' => now()]); // what the chat does on every new message
        $this->analyze();
        $this->assertCount(1, $this->sent); // not finished yet: it must be quiet for the analysis delay first

        $this->travel(20)->minutes();
        $this->analyze();

        $this->assertCount(2, $this->sent);
        $this->assertSame(2, ConversationAnalysis::where('conversation_id', $c->id)->count()); // old result kept, new one added
        $this->assertSame(2, ConversationAnalysis::valid()->where('conversation_id', $c->id)->orderByDesc('id')->first()->employee_message_count);
    }

    public function test_a_conversation_that_changed_while_the_agent_was_thinking_is_not_stored_for_the_old_version(): void
    {
        $c = $this->conversation();
        $this->agentAnswers(function ($payload) use ($c) {
            $this->message($c, 'user', 'Sent while the agent was working'); // the conversation moves on

            return json_encode(['request_ref' => $payload['request_ref'], 'rubric_version' => 'v1', 'task_label' => 'X', 'topic_ids' => [$payload['topics'][0]['id']],
                'scores' => array_fill_keys(AnalysisContract::DIMENSIONS, 3), 'tip' => 'Y']);
        });

        $r = $this->analyze();

        $this->assertSame(1, $r['stale']);
        $this->assertSame(0, ConversationAnalysis::count());
    }

    // ---- when the agent misbehaves ---------------------------------------------------------------

    public function test_a_reply_that_breaks_the_contract_is_a_failure_never_scores_and_is_retried_later(): void
    {
        $this->agentAnswers(fn () => 'Sure! Here are the scores: clarity 5');
        $c = $this->conversation();

        $r = $this->analyze();

        $this->assertSame(1, $r['failed']);
        $a = ConversationAnalysis::sole();
        $this->assertSame(['failed', 'invalid:not_json', 1], [$a->status, $a->error, $a->attempts]);
        $this->assertNull($a->clarity);
        $this->assertNull($a->average);
        $this->assertTrue($a->next_retry_at->isFuture());

        $this->analyze(); // too early: no new call
        $this->assertCount(1, $this->sent);

        $this->travel(16)->minutes();
        $this->analyze();
        $this->assertCount(2, $this->sent);
        $this->assertSame(2, $a->fresh()->attempts);
    }

    public function test_after_three_attempts_a_conversation_is_left_alone(): void
    {
        $this->agentAnswers(fn () => 'nope');
        $this->conversation();

        foreach ([0, 16, 16, 16] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->analyze();
        }

        $this->assertCount(3, $this->sent);
        $a = ConversationAnalysis::sole();
        $this->assertSame([3, null], [$a->attempts, $a->next_retry_at]);
    }

    public function test_a_wrong_score_or_unknown_topic_is_rejected_and_stores_nothing_but_the_failure(): void
    {
        $this->agentAnswers(fn ($p) => json_encode(['request_ref' => $p['request_ref'], 'rubric_version' => 'v1', 'task_label' => 'X', 'topic_ids' => [9999],
            'scores' => array_fill_keys(AnalysisContract::DIMENSIONS, 3), 'tip' => 'Y']));
        $this->conversation();

        $this->analyze();

        $a = ConversationAnalysis::sole();
        $this->assertSame(['failed', 'invalid:bad_topics', null, []], [$a->status, $a->error, $a->task_label, $a->topic_ids ?? []]);
    }

    public function test_a_conversation_the_agent_cannot_assess_is_recorded_without_scores(): void
    {
        $this->agentAnswers(fn ($p) => json_encode(['request_ref' => $p['request_ref'], 'rubric_version' => 'v1', 'error' => 'unassessable', 'detail' => 'no_assessable_content']));
        $this->conversation();

        $r = $this->analyze();

        $this->assertSame(1, $r['unassessable']);
        $a = ConversationAnalysis::sole();
        $this->assertSame(['unassessable', 'no_assessable_content', null, null], [$a->status, $a->error, $a->clarity, $a->average]);

        $this->analyze();
        $this->assertCount(1, $this->sent); // not asked again
    }

    public function test_a_rate_limited_agent_stops_the_batch_and_is_not_hammered(): void
    {
        $this->agentAnswers(status: 429);
        $first = $this->conversation(attrs: ['last_message_at' => now()->subHours(2)]);
        $this->conversation(attrs: ['last_message_at' => now()->subHours(3)]);

        $r = $this->analyze();

        $this->assertSame('rate_limited', $r['stopped']);
        $this->assertCount(1, $this->sent); // the second conversation was not tried
        $a = ConversationAnalysis::sole();
        $this->assertSame([$first->id, 'failed', 'rate_limited'], [$a->conversation_id, $a->status, $a->error]);
        $this->assertTrue($a->next_retry_at->isFuture());
        $this->assertNull($a->clarity);

        $this->analyze(); // still in the waiting period
        $this->assertCount(2, $this->sent); // only the second conversation (no result yet) is tried, then it stops again
    }

    public function test_an_exhausted_system_budget_stops_the_run_before_any_call(): void
    {
        $this->agentAnswers();
        $this->conversation();
        Settings::update(['system_daily_token_budget' => 100], $this->super);
        UsageRecord::create(['attempt_id' => 'seed#1', 'request_id' => 'seed', 'user_id' => $this->super->id, 'model' => 'm', 'purpose' => 'analysis', 'input_tokens' => 100, 'output_tokens' => 0,
            'is_estimate' => false, 'status' => 'success', 'work_date' => BusinessDay::today()]);

        $r = $this->analyze();

        $this->assertSame('budget', $r['stopped']);
        $this->assertSame([], $this->sent);
        $this->assertSame(0, ConversationAnalysis::count());
    }

    public function test_an_empty_budget_setting_means_no_limit(): void
    {
        $this->agentAnswers();
        $this->conversation();
        $this->assertNull(Settings::get('system_daily_token_budget'));

        $this->assertSame(1, $this->analyze()['valid']);
    }

    // ---- a broken agent that is fixed later --------------------------------------------------------

    public function test_a_request_the_agent_refuses_is_retried_after_a_wait_instead_of_never(): void
    {
        $this->agentAnswers(status: 404); // a wrong profile name is answered like this
        $c = $this->analysisChat();

        $r = $this->analyze();

        $this->assertSame('bad_request', $r['stopped']);
        $a = ConversationAnalysis::sole();
        $this->assertSame([$c->id, 'failed', 'bad_request', 1], [$a->conversation_id, $a->status, $a->error, $a->attempts]);
        $this->assertNull($a->clarity);
        $this->assertTrue($a->next_retry_at->between(now()->addMinutes(59), now()->addMinutes(61)), 'a retry time about an hour away is set');

        $this->analyze(); // too early: not asked again
        $this->assertCount(1, $this->sent);

        $this->agentStatus = 200; // the agent was fixed
        $this->travel(61)->minutes();
        $this->assertSame(1, $this->analyze()['valid']);
        $this->assertSame(['valid', 2], [$a->fresh()->status, $a->fresh()->attempts]);
    }

    public function test_a_passed_connection_test_makes_failed_analyses_due_at_once(): void
    {
        $this->agentAnswers(status: 404);
        $this->analysisChat();
        $this->analyze();
        $a = ConversationAnalysis::sole();
        $this->assertTrue($a->next_retry_at->isFuture());

        $this->agentStatus = 200; // fixed, then an admin presses Test connection
        $check = app(AgentService::class)->test($this->report, $this->super);
        $this->assertTrue($check->ok);

        $this->assertTrue($a->fresh()->next_retry_at->lte(now()), 'no waiting for the timer');
        $this->assertSame(1, $this->analyze()['valid']);
    }

    public function test_a_failed_connection_test_does_not_wake_anything(): void
    {
        $this->agentAnswers(status: 404);
        $this->analysisChat();
        $this->analyze();
        $when = ConversationAnalysis::sole()->next_retry_at;

        $this->assertFalse(app(AgentService::class)->test($this->report, $this->super)->ok); // the agent still answers 404

        $this->assertEquals($when, ConversationAnalysis::sole()->next_retry_at);
    }

    public function test_a_failed_row_with_attempts_left_but_no_retry_time_is_retried(): void
    {
        $this->agentAnswers();
        $c = $this->analysisChat();
        ConversationAnalysis::create($this->failedRow($c, attempts: 1, retryAt: null)); // what the old rule wrote for a refused request

        $this->assertSame(1, $this->analyze()['valid']);
        $this->assertSame(2, ConversationAnalysis::sole()->attempts);
    }

    public function test_waking_failed_analyses_leaves_out_other_agents_and_rows_with_no_attempts_left(): void
    {
        $other = Agent::create(['name' => 'Other', 'slug' => 'other', 'type' => 'report', 'base_url' => 'https://o.example.com', 'profile' => 'o']);
        $mine = ConversationAnalysis::create($this->failedRow($this->analysisChat(), attempts: 1, retryAt: now()->addHour()));
        $spent = ConversationAnalysis::create($this->failedRow($this->analysisChat(), attempts: ConversationAnalyzer::MAX_ATTEMPTS, retryAt: null));
        $others = ConversationAnalysis::create($this->failedRow($this->analysisChat(), attempts: 1, retryAt: now()->addHour(), agentId: $other->id));

        $this->assertSame(1, ConversationAnalysis::retryFailedNow($this->report->id));

        $this->assertTrue($mine->fresh()->next_retry_at->lte(now()));
        $this->assertNull($spent->fresh()->next_retry_at);
        $this->assertTrue($others->fresh()->next_retry_at->isFuture());
    }

    /** A chat that has been quiet for over an hour, so it is ready to be analyzed. */
    private function analysisChat(): Conversation
    {
        return $this->conversation();
    }

    private function failedRow(Conversation $c, int $attempts, $retryAt, ?int $agentId = null): array
    {
        $messages = Message::where('conversation_id', $c->id)->orderBy('id')->get(['id', 'role', 'content'])->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'content' => $m->content])->all();

        return ['conversation_id' => $c->id, 'user_id' => $c->user_id, 'agent_id' => $agentId ?? $this->report->id, 'rubric_version' => AnalysisContract::RUBRIC_VERSION,
            'version_hash' => AnalysisContract::versionHash($messages), 'message_count' => count($messages), 'employee_message_count' => 1,
            'status' => ConversationAnalysis::FAILED, 'error' => 'bad_request', 'attempts' => $attempts, 'next_retry_at' => $retryAt];
    }

    // ---- the command and the schedule ------------------------------------------------------------

    public function test_the_command_runs_the_analysis_and_reports_what_it_did(): void
    {
        $this->agentAnswers();
        $this->conversation();

        $this->artisan('thryve:analyze-conversations')->expectsOutputToContain('Analyzed 1 conversation(s)')->assertSuccessful();

        $this->assertSame(1, ConversationAnalysis::count());
    }

    public function test_the_command_says_so_when_analysis_is_off(): void
    {
        Settings::update(['analysis_enabled' => false], $this->super);

        $this->artisan('thryve:analyze-conversations')->expectsOutputToContain('not running')->assertSuccessful();
    }

    public function test_the_analysis_is_scheduled_every_five_minutes(): void
    {
        $event = collect(Schedule::events())->first(fn ($e) => str_contains($e->command, 'thryve:analyze-conversations'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_analysis_is_off_by_default(): void
    {
        $this->assertFalse(config('thryve.settings.analysis_enabled.default'));
        $this->assertSame(5, config('thryve.settings.analysis_batch_size.default'));
    }
}
