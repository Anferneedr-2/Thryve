<?php

namespace Tests\Feature;

use App\Ai\StreamTransport;
use App\Models\AuditEntry;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use App\Support\Audit;
use App\Support\Impersonation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** A Super Admin views the app as an employee without touching the employee's own session, attendance or password. */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private User $super;

    private User $employee;

    private string $superCookie;

    private string $employeeCookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
        config(['services.hermes.url' => 'http://hermes.test/p/bot', 'services.hermes.key' => 'synthetic']);
        $this->super = $this->makeUser(Role::SUPER_ADMIN);
        $this->employee = $this->makeUser(Role::EMPLOYEE);
        [, $this->superCookie] = $this->signIn($this->super);
        [, $this->employeeCookie] = $this->signIn($this->employee);
    }

    private function viewAs(?User $target = null)
    {
        return $this->device($this->superCookie)->postJson('/api/users/'.($target ?? $this->employee)->id.'/impersonate');
    }

    private function me(string $cookie)
    {
        return $this->device($cookie)->getJson('/api/auth/me');
    }

    // ---- who can start, and who can be viewed ---------------------------------------------------------

    public function test_only_a_super_admin_can_start_viewing_as_someone(): void
    {
        $this->device(null)->postJson("/api/users/{$this->employee->id}/impersonate")->assertStatus(401);
        $this->device($this->employeeCookie)->postJson("/api/users/{$this->employee->id}/impersonate")->assertStatus(403);
        $this->device($this->cookieFor(Role::ADMIN))->postJson("/api/users/{$this->employee->id}/impersonate")->assertStatus(403);

        $this->assertSame(0, AuditEntry::where('action', 'impersonation.started')->count());
        $this->viewAs()->assertOk();
    }

    public function test_these_accounts_cannot_be_viewed_as(): void
    {
        $deactivated = $this->makeUser(Role::EMPLOYEE, ['is_active' => false]);
        $notAccepted = $this->makeUser(Role::EMPLOYEE, acknowledged: false);
        $mustChange = $this->makeUser(Role::EMPLOYEE, ['must_change_password' => true]);
        $targets = [$this->makeUser(Role::ADMIN), $this->makeUser(Role::SUPER_ADMIN), $this->super, $deactivated, $notAccepted, $mustChange];

        foreach ($targets as $target) {
            $this->viewAs($target)->assertStatus(422)->assertJsonPath('error.code', 'cannot_view_as');
        }

        $this->assertSame($this->super->id, $this->me($this->superCookie)->json('data.id'));
        $this->assertSame(0, AuditEntry::where('action', 'impersonation.started')->count());
    }

    public function test_the_people_list_says_why_the_button_is_off(): void
    {
        $notAccepted = $this->makeUser(Role::EMPLOYEE, acknowledged: false);

        $rows = collect($this->device($this->superCookie)->getJson('/api/users')->assertOk()->json('data'))->keyBy('id');

        $this->assertNull($rows[$this->employee->id]['view_as_blocked']);
        $this->assertStringContainsString('usage policy', $rows[$notAccepted->id]['view_as_blocked']);
        $this->assertArrayNotHasKey('view_as_blocked', $rows[$this->super->id]);
    }

    // ---- the employee is left alone -------------------------------------------------------------------

    public function test_starting_leaves_the_employees_own_session_and_records_alone(): void
    {
        $before = User::find($this->employee->id);
        $workSessions = DB::table('work_sessions')->count();

        $this->viewAs()->assertOk();

        $after = User::find($this->employee->id);
        $this->assertSame($before->session_id, $after->session_id);
        $this->assertEquals($before->last_login_at, $after->last_login_at);
        $this->assertSame($workSessions, DB::table('work_sessions')->count());
        $this->assertSame($this->employee->id, $this->me($this->employeeCookie)->assertOk()->json('data.id')); // their own browser stays signed in
        $viewing = $this->me($this->superCookie)->assertOk();
        $this->assertSame($this->employee->id, $viewing->json('data.id'));
        $this->assertSame($this->super->id, $viewing->json('data.viewing_as_by.id'));
    }

    public function test_returning_restores_the_super_admin_and_keeps_their_session_valid(): void
    {
        $superSession = User::find($this->super->id)->session_id;
        $this->viewAs()->assertOk();
        $this->device($this->superCookie)->getJson('/api/users')->assertStatus(403); // admin pages are closed while viewing as

        $this->device($this->superCookie)->postJson('/api/auth/impersonation/stop')->assertOk();

        $this->assertSame($this->super->id, $this->me($this->superCookie)->json('data.id'));
        $this->assertNull($this->me($this->superCookie)->json('data.viewing_as_by'));
        $this->assertSame($superSession, User::find($this->super->id)->session_id);
        $this->device($this->superCookie)->getJson('/api/users')->assertOk();
        $this->device($this->employeeCookie)->postJson('/api/auth/impersonation/stop')->assertStatus(409);
    }

    public function test_starting_and_ending_are_audited_with_the_super_admin_as_actor(): void
    {
        $this->viewAs()->assertOk();
        $this->device($this->superCookie)->postJson('/api/auth/impersonation/stop')->assertOk();

        foreach (['impersonation.started', 'impersonation.ended'] as $action) {
            $entry = AuditEntry::where('action', $action)->sole();
            $this->assertSame($this->super->id, $entry->actor_id);
            $this->assertSame($this->employee->id, $entry->target_id);
            $this->assertNotNull($entry->ip_address);
        }
    }

    public function test_an_action_done_while_viewing_as_is_recorded_under_the_super_admin(): void
    {
        $request = request();
        $request->setLaravelSession(app('session.store'));
        session()->put(Impersonation::KEY, $this->super->id);

        $entry = Audit::log('test.action', $this->employee, $this->employee, ['password' => 'x']);

        $this->assertSame($this->super->id, $entry->actor_id);
        $this->assertSame(['id' => $this->employee->id, 'username' => $this->employee->username], $entry->metadata['acting_as']);
        $this->assertArrayNotHasKey('password', $entry->metadata);
    }

    // ---- the one-session rule ---------------------------------------------------------------------------

    public function test_ending_or_replacing_the_super_admins_session_ends_viewing_as(): void
    {
        $this->viewAs()->assertOk();
        User::whereKey($this->super->id)->update(['session_id' => null]);
        $this->me($this->superCookie)->assertStatus(401)->assertJsonPath('error.code', 'session_terminated');

        [, $cookie] = $this->signIn($this->super);
        $this->device($cookie)->postJson("/api/users/{$this->employee->id}/impersonate")->assertOk();
        $this->signIn($this->super); // the Super Admin signs in somewhere else
        $this->me($cookie)->assertStatus(401)->assertJsonPath('error.code', 'session_replaced');
    }

    public function test_deactivating_either_account_ends_viewing_as(): void
    {
        $this->viewAs()->assertOk();
        User::whereKey($this->employee->id)->update(['is_active' => false]);
        $this->me($this->superCookie)->assertStatus(401)->assertJsonPath('error.code', 'account_deactivated');

        User::whereKey($this->employee->id)->update(['is_active' => true]);
        [, $cookie] = $this->signIn($this->super);
        $this->device($cookie)->postJson("/api/users/{$this->employee->id}/impersonate")->assertOk();
        User::whereKey($this->super->id)->update(['is_active' => false]);
        $this->me($cookie)->assertStatus(401)->assertJsonPath('error.code', 'account_deactivated');
    }

    public function test_normal_logins_still_follow_the_one_session_rule(): void
    {
        $this->signIn($this->employee); // a second login for the employee

        $this->me($this->employeeCookie)->assertStatus(401)->assertJsonPath('error.code', 'session_replaced');
    }

    public function test_signing_out_while_viewing_as_ends_only_the_super_admins_session(): void
    {
        $employeeSession = User::find($this->employee->id)->session_id;
        $this->viewAs()->assertOk();

        $this->device($this->superCookie)->postJson('/api/auth/logout')->assertOk();

        $this->assertNull(User::find($this->super->id)->session_id);
        $this->assertSame($employeeSession, User::find($this->employee->id)->session_id);
        $this->me($this->employeeCookie)->assertOk();
    }

    // ---- what stays closed, and what works ----------------------------------------------------------------

    public function test_attendance_is_not_counted_while_viewing_as(): void
    {
        $this->viewAs()->assertOk();
        $sessions = DB::table('work_sessions')->get()->toArray();
        $days = DB::table('attendance_days')->get()->toArray();

        $this->device($this->superCookie)->postJson('/api/attendance/heartbeat', ['activity_age_seconds' => 1])->assertOk()->assertJsonPath('data.paused', true);
        $this->device($this->superCookie)->postJson('/api/attendance/close')->assertOk()->assertJsonPath('data.paused', true);

        $this->assertEquals($sessions, DB::table('work_sessions')->get()->toArray());
        $this->assertEquals($days, DB::table('attendance_days')->get()->toArray());
    }

    public function test_password_and_policy_acceptance_stay_closed_while_viewing_as(): void
    {
        $this->viewAs()->assertOk();

        $this->device($this->superCookie)->postJson('/api/account/password', ['current_password' => self::PW, 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password'])
            ->assertStatus(403)->assertJsonPath('error.code', 'not_while_viewing_as');
        $this->device($this->superCookie)->postJson('/api/policy/acknowledge', ['accept' => true])->assertStatus(403)->assertJsonPath('error.code', 'not_while_viewing_as');

        $this->assertTrue(Hash::check(self::PW, User::find($this->employee->id)->password));
    }

    public function test_chat_works_as_the_employee_and_is_charged_to_them(): void
    {
        Http::fake(['*' => Http::response(['model' => 'x', 'choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 5]])]);
        $this->viewAs()->assertOk();

        $this->device($this->superCookie)->postJson('/api/chat', ['message' => 'Help me'])->assertOk()->assertJsonPath('data.reply.content', 'Hello');

        $this->assertSame($this->employee->id, Conversation::sole()->user_id);
        $this->assertSame($this->employee->id, UsageRecord::sole()->user_id);
        $this->assertSame(1, count($this->device($this->superCookie)->getJson('/api/conversations')->json('data')));
    }

    public function test_a_streamed_answer_is_not_cut_by_the_one_session_check_while_viewing_as(): void
    {
        $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => 'stop']]])."\n\n"
            .'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5]])."\n\ndata: [DONE]\n\n";
        $this->mock(StreamTransport::class, function ($mock) use ($sse) {
            $mock->shouldReceive('read')->andReturnUsing(function ($url, $headers, $payload, $timeout, $receive) use ($sse) {
                $receive($sse);

                return false;
            });
        });
        $this->viewAs()->assertOk();

        $body = $this->device($this->superCookie)->postJson('/api/chat', ['message' => 'Hi'], ['Accept' => 'text/event-stream', 'X-Chat-Stream-Id' => (string) Str::uuid()])->assertOk()->streamedContent();

        $this->assertStringContainsString('event: complete', $body);
        $this->assertDatabaseHas('messages', ['role' => 'assistant', 'content' => 'Hello']);
        $this->assertSame($this->employee->id, Conversation::sole()->user_id);
    }
}
