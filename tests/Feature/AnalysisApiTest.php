<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AnalysisTopic;
use App\Models\AuditEntry;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** The topic list, the employee's own scores and the admin report: all read from stored results, never from the AI. */
class AnalysisApiTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private User $super;

    private User $admin;

    private User $ann;

    private User $bob;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        $this->super = $this->makeUser(Role::SUPER_ADMIN);
        $this->admin = $this->makeUser(Role::ADMIN);
        $this->ann = $this->makeUser(Role::EMPLOYEE);
        $this->bob = $this->makeUser(Role::EMPLOYEE);
        Http::fake(); // no test here may reach an AI
    }

    private function as(User $u): \Illuminate\Testing\TestCase|static
    {
        [, $cookie] = $this->signIn($u);

        return $this->device($cookie);
    }

    /** A conversation with a stored valid result. $scores are in the order clarity, context, specifics, refinement, verification. */
    private function analysis(User $user, array $scores = [4, 4, 4, 3, 2], int $daysAgo = 1, array $over = [], array $conv = []): ConversationAnalysis
    {
        $c = Conversation::create($conv + ['user_id' => $user->id, 'title' => 'Chat '.++$this->n, 'last_message_at' => now()->subDays($daysAgo)->subHour()]);
        Message::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'A question', 'status' => 'ok']);
        $used = array_filter($scores, fn ($s) => $s !== null);

        return ConversationAnalysis::create($over + [
            'conversation_id' => $c->id, 'user_id' => $user->id, 'rubric_version' => 'v1', 'version_hash' => hash('sha256', 'v'.$this->n), 'message_count' => 2, 'employee_message_count' => 2,
            'status' => 'valid', 'attempts' => 1, 'task_label' => 'Draft client email', 'topic_ids' => [1], 'tip' => 'Ask for sources.',
            'clarity' => $scores[0], 'context' => $scores[1], 'specifics' => $scores[2], 'refinement' => $scores[3], 'verification' => $scores[4],
            'dimensions_scored' => count($used), 'average' => round(array_sum($used) / count($used), 2), 'analyzed_at' => now()->subDays($daysAgo),
        ]);
    }

    // ---- topics ----------------------------------------------------------------------------------

    public function test_admins_manage_the_topic_list_and_others_cannot(): void
    {
        $this->as($this->ann)->getJson('/api/analysis/topics')->assertStatus(403);
        $this->as($this->ann)->postJson('/api/analysis/topics', ['name' => 'X', 'description' => 'Y'])->assertStatus(403);
        $this->device(null)->getJson('/api/analysis/topics')->assertStatus(401);

        foreach ([$this->admin, $this->super] as $person) {
            $rows = $this->as($person)->getJson('/api/analysis/topics')->assertOk()->json('data');
            $this->assertCount(11, $rows);
            $this->assertSame('Business writing', $rows[0]['name']);
            $this->assertTrue(collect($rows)->firstWhere('name', 'Other work question')['is_fallback']);
        }
    }

    public function test_a_topic_can_be_added_edited_and_deactivated_and_each_change_is_audited(): void
    {
        $made = $this->as($this->admin)->postJson('/api/analysis/topics', ['name' => 'Customer support replies', 'description' => 'Answering customer questions'])->assertCreated();
        $id = $made->json('data.id');
        $this->assertTrue($made->json('data.is_active'));
        $this->assertGreaterThan(100, $made->json('data.priority')); // added at the end

        $this->as($this->admin)->patchJson("/api/analysis/topics/{$id}", ['description' => 'Replies to customers by email or chat'])->assertOk();
        $this->as($this->admin)->patchJson("/api/analysis/topics/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertSame('Replies to customers by email or chat', AnalysisTopic::find($id)->description);
        $this->assertFalse(AnalysisTopic::find($id)->is_active);
        $this->assertSame(1, AuditEntry::where('action', 'analysis.topic_created')->count());
        $this->assertSame(2, AuditEntry::where('action', 'analysis.topic_updated')->count());
    }

    public function test_topic_input_is_validated(): void
    {
        $this->as($this->admin)->postJson('/api/analysis/topics', ['name' => 'Business writing', 'description' => 'Duplicate'])->assertStatus(422)->assertJsonPath('error.details.name.0', 'A topic with this name already exists.');
        $this->as($this->admin)->postJson('/api/analysis/topics', ['name' => '', 'description' => 'x'])->assertStatus(422);
        $this->as($this->admin)->postJson('/api/analysis/topics', ['name' => str_repeat('a', 81), 'description' => 'x'])->assertStatus(422);
        $this->as($this->admin)->postJson('/api/analysis/topics', ['name' => 'Fine', 'description' => str_repeat('d', 201)])->assertStatus(422);
        $this->as($this->admin)->patchJson('/api/analysis/topics/999999', ['description' => 'x'])->assertStatus(404);
    }

    public function test_the_fallback_topic_keeps_its_name_and_stays_active(): void
    {
        $fallback = AnalysisTopic::where('is_fallback', true)->first();

        $this->as($this->admin)->patchJson("/api/analysis/topics/{$fallback->id}", ['name' => 'Something else'])->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/analysis/topics/{$fallback->id}", ['is_active' => false])->assertStatus(422);
        $this->as($this->admin)->patchJson("/api/analysis/topics/{$fallback->id}", ['description' => 'A clearer description'])->assertOk();

        $this->assertSame('Other work question', $fallback->fresh()->name);
        $this->assertTrue($fallback->fresh()->is_active);
    }

    public function test_the_topic_list_shows_how_many_analyzed_conversations_use_each_topic(): void
    {
        $this->analysis($this->ann, over: ['topic_ids' => [1, 2]]);
        $this->analysis($this->bob, over: ['topic_ids' => [1]]);

        $rows = collect($this->as($this->admin)->getJson('/api/analysis/topics')->json('data'))->pluck('conversations', 'id');

        $this->assertSame(2, $rows[1]);
        $this->assertSame(1, $rows[2]);
        $this->assertSame(0, $rows[3]);
    }

    // ---- the admin report ------------------------------------------------------------------------

    public function test_only_admins_can_read_the_company_report(): void
    {
        $this->as($this->ann)->getJson('/api/reports/analysis')->assertStatus(403);
        $this->device(null)->getJson('/api/reports/analysis')->assertStatus(401);
        $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk();
        $this->as($this->super)->getJson('/api/reports/analysis')->assertOk();
    }

    public function test_the_report_reads_stored_results_and_never_calls_the_ai(): void
    {
        $this->analysis($this->ann);

        $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk();

        Http::assertNothingSent();
        $this->assertSame(1, AuditEntry::where('action', 'reports.analysis.viewed')->count());
    }

    public function test_an_empty_report_says_so_instead_of_showing_zeros(): void
    {
        $d = $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk()->json('data');

        $this->assertSame(0, $d['totals']['conversations']);
        $this->assertNull($d['totals']['average']);
        $this->assertSame([null], array_unique(array_column($d['dimensions'], 'average')));
        $this->assertSame([], $d['topics']);
        $this->assertSame([], $d['recommendations']);
        $this->assertFalse($d['state']['enabled']);
        $this->assertFalse($d['state']['agent_ready']);
        $this->assertSame(11, $d['state']['topics_active']);
    }

    public function test_totals_dimension_averages_topics_and_tasks_are_computed_from_the_rows(): void
    {
        $this->analysis($this->ann, [5, 4, 5, 3, 1], over: ['topic_ids' => [1], 'task_label' => 'Draft client email']);
        $this->analysis($this->ann, [3, 2, 3, 2, 1], over: ['topic_ids' => [1, 7], 'task_label' => 'draft client email']);
        $this->analysis($this->bob, [4, 4, 4, null, 2], over: ['topic_ids' => [3], 'task_label' => 'Monthly expense totals', 'employee_message_count' => 1]);

        $d = $this->as($this->admin)->getJson('/api/reports/analysis?days=7')->assertOk()->json('data');
        $dims = collect($d['dimensions'])->keyBy('key');

        $this->assertSame(3, $d['totals']['conversations']);
        $this->assertSame(3.1, $d['totals']['average']); // (3.6 + 2.2 + 3.5) / 3 = 3.1
        $this->assertSame([4, 3.33, 4], [$dims['clarity']['average'], $dims['context']['average'], $dims['specifics']['average']]);
        $this->assertSame([2.5, 2], [$dims['refinement']['average'], $dims['refinement']['samples']]); // the empty one is left out
        $this->assertSame([3], [$dims['clarity']['samples']]);
        $this->assertSame(['Business writing', 2, 66.7], [$d['topics'][0]['name'], $d['topics'][0]['conversations'], $d['topics'][0]['share']]);
        $this->assertSame(3, count($d['topics'])); // topics 1, 7 and 3
        $this->assertSame(['Draft client email', 2], [$d['tasks'][0]['label'], $d['tasks'][0]['conversations']]); // same label in a different case is one task
    }

    public function test_each_conversation_counts_once_with_its_newest_result_of_the_current_rubric(): void
    {
        $first = $this->analysis($this->ann, [1, 1, 1, 1, 1]);
        ConversationAnalysis::create(['conversation_id' => $first->conversation_id, 'user_id' => $this->ann->id, 'rubric_version' => 'v1', 'version_hash' => hash('sha256', 'newer'), 'message_count' => 4, 'employee_message_count' => 2,
            'status' => 'valid', 'attempts' => 1, 'task_label' => 'Newer', 'topic_ids' => [1], 'tip' => 't', 'clarity' => 5, 'context' => 5, 'specifics' => 5, 'refinement' => 5, 'verification' => 5, 'dimensions_scored' => 5, 'average' => 5, 'analyzed_at' => now()]);
        ConversationAnalysis::create(['conversation_id' => $first->conversation_id, 'user_id' => $this->ann->id, 'rubric_version' => 'v2', 'version_hash' => hash('sha256', 'other rubric'), 'message_count' => 4, 'employee_message_count' => 2,
            'status' => 'valid', 'attempts' => 1, 'task_label' => 'Other rubric', 'topic_ids' => [1], 'tip' => 't', 'clarity' => 1, 'context' => 1, 'specifics' => 1, 'refinement' => 1, 'verification' => 1, 'dimensions_scored' => 5, 'average' => 1, 'analyzed_at' => now()->addMinute()]);
        $this->analysis($this->ann, over: ['status' => 'failed', 'clarity' => null, 'context' => null, 'specifics' => null, 'refinement' => null, 'verification' => null, 'average' => null, 'dimensions_scored' => null, 'task_label' => null, 'topic_ids' => null]);

        $d = $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk()->json('data');

        $this->assertSame([1, 5], [$d['totals']['conversations'], $d['totals']['average']]);
        $this->assertSame('Newer', $d['tasks'][0]['label']);
    }

    public function test_the_period_and_the_person_and_department_filters_apply(): void
    {
        $this->analysis($this->ann, [5, 5, 5, 5, 5], daysAgo: 2);
        $this->analysis($this->bob, [1, 1, 1, 1, 1], daysAgo: 3);
        $this->analysis($this->ann, [3, 3, 3, 3, 3], daysAgo: 40);
        $dep = Department::create(['name' => 'Sales']);
        DB::table('department_user')->insert(['department_id' => $dep->id, 'user_id' => $this->bob->id]);

        $read = fn (string $q) => $this->as($this->admin)->getJson("/api/reports/analysis?{$q}")->assertOk()->json('data.totals');

        $this->assertSame([2, 3], [$read('days=7')['conversations'], $read('days=7')['average']]);
        $this->assertSame(3, $read('days=90')['conversations']);
        $this->assertSame([1, 5], [$read("days=7&user_id={$this->ann->id}")['conversations'], $read("days=7&user_id={$this->ann->id}")['average']]);
        $this->assertSame([1, 1], [$read("days=7&department_id={$dep->id}")['conversations'], $read("days=7&department_id={$dep->id}")['average']]);
        $this->as($this->admin)->getJson('/api/reports/analysis?days=5')->assertStatus(422);
    }

    public function test_conversations_deleted_by_their_owner_stay_in_the_report_and_dates_are_philippine_dates(): void
    {
        $this->analysis($this->ann, daysAgo: 2, conv: ['deleted_at' => now()]);

        $d = $this->as($this->admin)->getJson('/api/reports/analysis?days=7')->assertOk()->json('data');

        $this->assertSame(1, $d['totals']['conversations']);
        $this->assertCount(7, $d['daily']);
        $withData = array_values(array_filter($d['daily'], fn ($r) => $r['conversations'] > 0));
        $this->assertCount(1, $withData);
        $this->assertSame([null], array_unique(array_column(array_filter($d['daily'], fn ($r) => $r['conversations'] === 0), 'average'))); // a gap, never zero
    }

    public function test_coverage_puts_every_conversation_in_exactly_one_state(): void
    {
        $this->analysis($this->ann);                                                                                         // analyzed
        $this->analysis($this->ann, over: ['status' => 'unassessable', 'average' => null, 'task_label' => null, 'topic_ids' => null]); // could not be assessed
        $stale = $this->analysis($this->ann);
        $stale->update(['analyzed_at' => now()->subDays(10)]);                                                                 // changed after it was analyzed
        $this->analysis($this->ann, over: ['status' => 'failed', 'attempts' => 3, 'task_label' => null, 'topic_ids' => null]);  // gave up
        $this->analysis($this->ann, over: ['status' => 'failed', 'attempts' => 1, 'task_label' => null, 'topic_ids' => null]);  // will be retried
        Conversation::create(['user_id' => $this->ann->id, 'title' => 'Not yet', 'last_message_at' => now()->subHour()]);
        $notYet = Conversation::latest('id')->first();
        Message::create(['conversation_id' => $notYet->id, 'role' => 'user', 'content' => 'hello', 'status' => 'ok']);                                    // never analyzed
        $empty = Conversation::create(['user_id' => $this->ann->id, 'title' => 'Only assistant', 'last_message_at' => now()->subHour()]);
        Message::create(['conversation_id' => $empty->id, 'role' => 'assistant', 'content' => 'Hi', 'status' => 'ok']);                                   // not eligible

        $c = $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk()->json('data.coverage');

        $this->assertSame(['eligible' => 6, 'analyzed' => 1, 'unassessable' => 1, 'stale' => 1, 'failed' => 1, 'pending' => 2, 'percent_analyzed' => 16.7], $c);
    }

    // ---- recommendations -------------------------------------------------------------------------

    private function fill(User $u, int $count, array $scores, int $daysAgo = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->analysis($u, $scores, $daysAgo);
        }
    }

    public function test_no_recommendation_appears_before_enough_conversations_are_analyzed(): void
    {
        $this->fill($this->ann, 9, [5, 5, 5, 4, 1]);

        $d = $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk()->json('data');

        $this->assertSame([], array_values(array_filter($d['recommendations'], fn ($r) => $r['id'] !== 'low_coverage')));
        $this->assertSame(10, $d['min_sample']);
    }

    public function test_the_weakest_area_and_the_top_topic_are_recommended_with_their_evidence(): void
    {
        $this->fill($this->ann, 10, [5, 5, 5, 4, 1]);

        $d = $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk()->json('data');
        $byId = collect($d['recommendations'])->keyBy('id');

        $this->assertSame(['verification', 1, 10], [$byId['weakest_area']['evidence']['dimension'], $byId['weakest_area']['evidence']['average'], $byId['weakest_area']['evidence']['conversations']]);
        $this->assertStringContainsString('Verification', $byId['weakest_area']['title']);
        $this->assertSame([10, 100], [$byId['top_topic']['evidence']['conversations'], $byId['top_topic']['evidence']['share']]);
        $this->assertArrayNotHasKey('decline', $byId->all());
    }

    public function test_a_drop_against_the_previous_period_is_flagged_only_when_both_periods_have_enough_data(): void
    {
        $this->fill($this->ann, 10, [2, 2, 2, 2, 2], daysAgo: 2);   // this period: 2.0
        $this->fill($this->ann, 10, [4, 4, 4, 4, 4], daysAgo: 9);   // previous 7 days: 4.0

        $d = $this->as($this->admin)->getJson('/api/reports/analysis?days=7')->assertOk()->json('data');
        $decline = collect($d['recommendations'])->firstWhere('id', 'decline');

        $this->assertNotNull($decline);
        $this->assertSame([4, 2, 10, 10], [$decline['evidence']['previous_average'], $decline['evidence']['average'], $decline['evidence']['previous_conversations'], $decline['evidence']['conversations']]);
        $this->assertSame([10, 4], [$d['totals']['previous_conversations'], $d['totals']['previous_average']]);
    }

    public function test_a_small_previous_period_is_not_used_to_claim_a_decline(): void
    {
        $this->fill($this->ann, 10, [2, 2, 2, 2, 2], daysAgo: 2);
        $this->fill($this->ann, 3, [5, 5, 5, 5, 5], daysAgo: 9);

        $d = $this->as($this->admin)->getJson('/api/reports/analysis?days=7')->assertOk()->json('data');

        $this->assertNull(collect($d['recommendations'])->firstWhere('id', 'decline'));
    }

    public function test_low_coverage_is_called_out(): void
    {
        $this->fill($this->ann, 2, [4, 4, 4, 4, 4]);
        for ($i = 0; $i < 10; $i++) {
            $c = Conversation::create(['user_id' => $this->ann->id, 'title' => "Waiting {$i}", 'last_message_at' => now()->subHour()]);
            Message::create(['conversation_id' => $c->id, 'role' => 'user', 'content' => 'hi', 'status' => 'ok']);
        }

        $d = $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk()->json('data');
        $rec = collect($d['recommendations'])->firstWhere('id', 'low_coverage');

        $this->assertNotNull($rec);
        $this->assertSame('warning', $rec['tone']);
        $this->assertSame([12, 2], [$rec['evidence']['eligible'], $rec['evidence']['analyzed']]);
    }

    public function test_nothing_in_the_report_names_or_ranks_a_person(): void
    {
        $this->fill($this->ann, 6, [1, 1, 1, 1, 1]);
        $this->fill($this->bob, 6, [5, 5, 5, 5, 5]);

        $json = $this->as($this->admin)->getJson('/api/reports/analysis')->assertOk()->getContent();

        $this->assertStringNotContainsString($this->ann->name, $json);
        $this->assertStringNotContainsString($this->bob->name, $json);
        $this->assertStringNotContainsString($this->ann->username, $json);
        $this->assertStringNotContainsString('"people"', $json);
    }

    // ---- an employee's own scores ----------------------------------------------------------------

    public function test_an_employee_sees_only_their_own_results(): void
    {
        $mine = $this->analysis($this->ann, [5, 5, 5, 5, 5], over: ['tip' => 'Keep going.', 'task_label' => 'My own task']);
        $this->analysis($this->bob, [1, 1, 1, 1, 1], over: ['tip' => 'Bob only tip', 'task_label' => 'Bob private task']);

        $r = $this->as($this->ann)->getJson('/api/analysis/mine')->assertOk();
        $json = $r->getContent();

        $this->assertSame([1, 5], [$r->json('data.totals.conversations'), $r->json('data.totals.average')]);
        $this->assertSame($mine->conversation_id, $r->json('data.recent.0.conversation_id'));
        $this->assertSame('Keep going.', $r->json('data.recent.0.tip'));
        $this->assertStringNotContainsString('Bob', $json);
        $this->assertStringNotContainsString('Bob private task', $json);
        $this->assertEquals(90, CarbonImmutable::parse($r->json('data.period.from'))->diffInDays(CarbonImmutable::parse($r->json('data.period.to'))) + 1);
    }

    public function test_the_own_scores_page_ignores_any_person_id_in_the_request(): void
    {
        $this->analysis($this->bob, [1, 1, 1, 1, 1], over: ['task_label' => 'Bob private task']);

        $r = $this->as($this->ann)->getJson("/api/analysis/mine?user_id={$this->bob->id}")->assertOk();

        $this->assertSame(0, $r->json('data.totals.conversations'));
        $this->assertStringNotContainsString('Bob private task', $r->getContent());
    }

    public function test_the_own_scores_page_needs_a_signed_in_person_and_reads_no_ai(): void
    {
        $this->device(null)->getJson('/api/analysis/mine')->assertStatus(401);
        $this->analysis($this->ann);

        $this->as($this->ann)->getJson('/api/analysis/mine')->assertOk();

        Http::assertNothingSent();
    }

    public function test_own_recommendations_need_five_conversations(): void
    {
        $this->fill($this->ann, 4, [5, 5, 5, 4, 1]);
        $this->assertSame([], $this->as($this->ann)->getJson('/api/analysis/mine')->json('data.recommendations'));

        $this->analysis($this->ann, [5, 5, 5, 4, 1]);
        $recs = $this->as($this->ann)->getJson('/api/analysis/mine')->json('data.recommendations');

        $this->assertSame('weakest_area', $recs[0]['id']);
        $this->assertStringContainsString('your lowest-scoring area', $recs[0]['title']);
        $this->assertSame(5, $this->as($this->ann)->getJson('/api/analysis/mine')->json('data.min_sample'));
    }

    public function test_the_first_tips_come_from_the_weakest_area_lowest_score_first(): void
    {
        $this->analysis($this->ann, [5, 5, 4, 5, 2], 3, ['tip' => 'Check the numbers.']);
        $this->analysis($this->ann, [5, 5, 5, 4, 1], 2, ['tip' => 'Ask for sources.']);
        $this->analysis($this->ann, [2, 4, 4, 4, 4], 1, ['tip' => 'State the goal first.']);

        $tips = $this->as($this->ann)->getJson('/api/analysis/mine')->assertOk()->json('data.priority_tips');

        $this->assertSame(['Ask for sources.', 'Check the numbers.', 'State the goal first.'], array_column($tips, 'tip'));
        $this->assertSame([true, false, false], array_column($tips, 'first'));
        $this->assertSame([true, true, false], array_column($tips, 'in_focus'));
        $this->assertSame(['Verification', 'Verification', 'Clarity'], array_column($tips, 'area_label'));
    }

    public function test_repeated_tips_show_once_and_at_most_four_are_offered(): void
    {
        foreach (range(1, 6) as $i) {
            $this->analysis($this->ann, [5, 5, 5, 5, 1 + ($i % 2)], $i, ['tip' => $i <= 2 ? 'Ask for sources.' : "Tip number {$i}."]);
        }

        $tips = $this->as($this->ann)->getJson('/api/analysis/mine')->assertOk()->json('data.priority_tips');

        $this->assertCount(4, $tips);
        $this->assertSame(1, count(array_keys(array_column($tips, 'tip'), 'Ask for sources.')));
    }

    public function test_tips_belong_to_the_signed_in_person_and_are_empty_without_results(): void
    {
        $this->analysis($this->bob, [1, 1, 1, 1, 1], over: ['tip' => 'Bob only tip']);

        $r = $this->as($this->ann)->getJson('/api/analysis/mine')->assertOk();

        $this->assertSame([], $r->json('data.priority_tips'));
        $this->assertStringNotContainsString('Bob only tip', $r->getContent());
    }

    public function test_the_state_says_when_analysis_is_ready(): void
    {
        Agent::create(['name' => 'Report AI', 'slug' => 'report-ai', 'type' => 'report', 'base_url' => 'https://r.example.com', 'profile' => 'report-ai', 'outbound_key' => 'key-report-1234567890']);
        Settings::update(['analysis_enabled' => true], $this->super);

        $state = $this->as($this->ann)->getJson('/api/analysis/mine')->json('data.state');

        $this->assertSame([true, true], [$state['enabled'], $state['agent_ready']]);
    }
}
