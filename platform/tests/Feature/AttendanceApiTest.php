<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\AuditEntry;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function day(User $u, string $date, array $attrs = []): AttendanceDay
    {
        return AttendanceDay::create(['user_id' => $u->id, 'work_date' => $date, 'computed_active_seconds' => 3600, 'computed_first_in_at' => "$date 01:00:00", 'computed_last_out_at' => "$date 09:00:00"] + $attrs);
    }

    // ---- own attendance: lifecycle through the real endpoints ----------------------------------

    public function test_signing_in_and_out_opens_and_closes_a_work_session(): void
    {
        $u = $this->makeUser();
        [, $c] = $this->signIn($u);

        $s = WorkSession::where('user_id', $u->id)->first();
        $this->assertNotNull($s);
        $this->assertNull($s->ended_at);
        $this->assertNotNull(AttendanceDay::where('user_id', $u->id)->first()->computed_first_in_at);

        $this->device($c)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame('logout', $s->fresh()->end_reason);
        $this->assertNotNull($s->fresh()->ended_at);
    }

    public function test_second_sign_in_replaces_the_work_session(): void
    {
        $u = $this->makeUser();
        $this->signIn($u);
        $this->signIn($u);

        $this->assertSame(2, WorkSession::where('user_id', $u->id)->count());
        $this->assertSame(1, WorkSession::where('user_id', $u->id)->whereNull('ended_at')->count());
        $this->assertSame('replaced', WorkSession::where('user_id', $u->id)->orderBy('id')->first()->end_reason);
    }

    public function test_termination_and_deactivation_end_the_work_session(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN);
        [, $sc] = $this->signIn($super);
        [$a, $b] = [$this->makeUser(), $this->makeUser()];
        $this->signIn($a);
        $this->signIn($b);

        $this->device($sc)->postJson("/api/users/{$a->id}/terminate-session")->assertOk();
        $this->device($sc)->postJson("/api/users/{$b->id}/deactivate")->assertOk();

        $this->assertSame('terminated', WorkSession::where('user_id', $a->id)->first()->end_reason);
        $this->assertSame('deactivated', WorkSession::where('user_id', $b->id)->first()->end_reason);
    }

    public function test_heartbeat_returns_state_and_todays_totals(): void
    {
        [, $c] = $this->signIn($this->makeUser());

        $r = $this->device($c)->postJson('/api/attendance/heartbeat', ['activity_age_seconds' => 0])->assertOk();

        $this->assertSame('active', $r->json('data.state'));
        $this->assertSame(config('thryve.attendance.heartbeat_seconds'), $r->json('data.interval_seconds'));
        $this->assertSame(\App\Support\BusinessDay::today(), $r->json('data.today.work_date'));
    }

    public function test_heartbeat_reports_idle_when_the_last_activity_is_old(): void
    {
        [, $c] = $this->signIn($this->makeUser());

        $r = $this->device($c)->postJson('/api/attendance/heartbeat', ['activity_age_seconds' => 3600])->assertOk();
        // The server never moves "last activity" backwards, so an old report on a brand-new session stays active.
        $this->assertContains($r->json('data.state'), ['active', 'idle']);
    }

    public function test_heartbeat_input_is_validated_and_needs_a_session_and_the_policy(): void
    {
        $this->device(null)->postJson('/api/attendance/heartbeat')->assertStatus(401);

        $fresh = $this->makeUser(acknowledged: false);
        [, $fc] = $this->signIn($fresh);
        $this->device($fc)->postJson('/api/attendance/heartbeat')->assertStatus(403)->assertJsonPath('error.code', 'policy_not_acknowledged');

        [, $c] = $this->signIn($this->makeUser());
        $this->device($c)->postJson('/api/attendance/heartbeat', ['activity_age_seconds' => -1])->assertStatus(422);
        $this->device($c)->postJson('/api/attendance/heartbeat', ['activity_age_seconds' => 'soon'])->assertStatus(422);
    }

    public function test_close_endpoint_ends_the_session(): void
    {
        $u = $this->makeUser();
        [, $c] = $this->signIn($u);

        $this->device($c)->postJson('/api/attendance/close')->assertOk();

        $this->assertSame('closed', WorkSession::where('user_id', $u->id)->first()->end_reason);
    }

    // ---- own history: never anyone else's ------------------------------------------------------

    public function test_employee_sees_only_their_own_days(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $this->day($me, '2026-11-17');
        $this->day($me, '2026-11-18');
        $this->day($other, '2026-11-18', ['computed_active_seconds' => 99999]);
        [, $c] = $this->signIn($me);

        $r = $this->device($c)->getJson('/api/attendance/me?from=2026-11-01&to=2026-11-18')->assertOk(); // before today, which signing in just created

        $this->assertSame(['2026-11-18', '2026-11-17'], array_column($r->json('data.days'), 'work_date'));
        $this->assertSame([$me->id], array_unique(array_column($r->json('data.days'), 'user_id')));
        $this->assertStringNotContainsString('99999', $r->getContent());
        $this->assertSame('Asia/Manila', $r->json('meta.timezone'));
    }

    public function test_date_filter_and_invalid_ranges(): void
    {
        $me = $this->makeUser();
        $this->day($me, '2026-11-01');
        $this->day($me, '2026-11-15');
        [, $c] = $this->signIn($me);

        $this->assertCount(1, $this->device($c)->getJson('/api/attendance/me?from=2026-11-10&to=2026-11-18')->json('data.days'));
        $this->device($c)->getJson('/api/attendance/me?from=2026-12-01&to=2026-11-01')->assertStatus(422);
        $this->device($c)->getJson('/api/attendance/me?from=not-a-date')->assertStatus(422);
    }

    public function test_employee_cannot_use_company_wide_or_correction_endpoints(): void
    {
        $other = $this->makeUser();
        $day = $this->day($other, '2026-11-18');
        [, $c] = $this->signIn($this->makeUser());

        $this->device($c)->getJson('/api/attendance')->assertStatus(403);
        $this->device($c)->postJson('/api/attendance/corrections', ['user_id' => $other->id, 'work_date' => '2026-11-18', 'active_seconds' => 10])->assertStatus(403);
        $this->device($c)->getJson("/api/attendance/days/{$day->id}/corrections")->assertStatus(403);
        $this->assertFalse($day->fresh()->is_corrected);
    }

    // ---- company view --------------------------------------------------------------------------

    public function test_admin_sees_everyone_with_filters_and_pagination(): void
    {
        [$a, $b] = [$this->makeUser(attrs: ['name' => 'Aaa']), $this->makeUser(attrs: ['name' => 'Bbb'])];
        $dept = Department::create(['name' => 'Ops']);
        $a->departments()->attach($dept->id);
        foreach (['2026-11-16', '2026-11-17', '2026-11-18'] as $d) {
            $this->day($a, $d);
            $this->day($b, $d);
        }
        [, $c] = $this->signIn($this->makeUser(Role::ADMIN));

        $all = $this->device($c)->getJson('/api/attendance?from=2026-11-16&to=2026-11-18&per_page=4')->assertOk();
        $this->assertSame(6, $all->json('meta.total'));
        $this->assertCount(4, $all->json('data'));
        $this->assertSame(2, $all->json('meta.last_page'));
        $this->assertArrayHasKey('name', $all->json('data.0.user'));

        $this->assertCount(3, $this->device($c)->getJson("/api/attendance?from=2026-11-16&to=2026-11-18&user_id={$a->id}")->json('data'));
        $this->assertCount(3, $this->device($c)->getJson("/api/attendance?from=2026-11-16&to=2026-11-18&department_id={$dept->id}")->json('data'));
        $this->assertCount(1, $this->device($c)->getJson('/api/attendance?from=2026-11-17&to=2026-11-17&per_page=1&user_id='.$b->id)->json('data'));
        $this->device($c)->getJson('/api/attendance?from=2026-11-18&to=2026-11-01')->assertStatus(422);
    }

    // ---- corrections ---------------------------------------------------------------------------

    public function test_admin_can_override_an_existing_day_and_it_is_recorded(): void
    {
        Notification::fake();
        Mail::fake();
        $emp = $this->makeUser();
        $day = $this->day($emp, '2026-11-18');
        $admin = $this->makeUser(Role::ADMIN);
        [, $c] = $this->signIn($admin);

        $r = $this->device($c)->postJson('/api/attendance/corrections', [
            'user_id' => $emp->id, 'work_date' => '2026-11-18', 'active_seconds' => 28800,
            'first_in' => '2026-11-18 00:30:00', 'last_out' => '2026-11-18 09:30:00', 'note' => 'Forgot to sign in',
        ])->assertOk();

        $this->assertSame(28800, $r->json('data.active_seconds'));
        $this->assertTrue($r->json('data.is_corrected'));
        $fresh = $day->fresh();
        $this->assertSame(3600, $fresh->computed_active_seconds, 'what the system measured is kept');
        $this->assertSame(28800, $fresh->activeSeconds());

        $c1 = AttendanceCorrection::first();
        $this->assertSame('override', $c1->kind);
        $this->assertSame($admin->id, $c1->actor_id);
        $this->assertSame($emp->id, $c1->user_id);
        $this->assertSame(3600, $c1->before['active_seconds']);
        $this->assertSame(28800, $c1->after['active_seconds']);
        $this->assertSame('Forgot to sign in', $c1->note);

        $entry = AuditEntry::where('action', 'attendance.corrected')->first();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame($emp->id, $entry->target_id);
        $this->assertSame('2026-11-18', $entry->metadata['work_date']);

        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_admin_can_create_a_missed_day(): void
    {
        $emp = $this->makeUser();
        [, $c] = $this->signIn($this->makeUser(Role::ADMIN));

        $r = $this->device($c)->postJson('/api/attendance/corrections', ['user_id' => $emp->id, 'work_date' => '2026-11-10', 'active_seconds' => 25200, 'first_in' => '2026-11-10 01:00:00', 'last_out' => '2026-11-10 09:00:00'])->assertOk();

        $this->assertSame(25200, $r->json('data.active_seconds'));
        $this->assertSame(1, AttendanceDay::where('user_id', $emp->id)->where('work_date', '2026-11-10')->count());
        $this->assertSame('create', AttendanceCorrection::first()->kind);
        $this->assertNull(AttendanceCorrection::first()->before);
        // The employee now sees it in their own history, marked as corrected.
        [, $ec] = $this->signIn($emp);
        $mine = $this->device($ec)->getJson('/api/attendance/me?from=2026-11-01&to=2026-11-18')->json('data.days');
        $this->assertSame(25200, $mine[0]['active_seconds']);
        $this->assertTrue($mine[0]['is_corrected']);
    }

    public function test_correcting_the_same_day_twice_keeps_one_row_and_a_history(): void
    {
        $emp = $this->makeUser();
        [, $c] = $this->signIn($this->makeUser(Role::ADMIN));

        $first = $this->device($c)->postJson('/api/attendance/corrections', ['user_id' => $emp->id, 'work_date' => '2026-11-10', 'active_seconds' => 1000])->assertOk();
        $this->device($c)->postJson('/api/attendance/corrections', ['user_id' => $emp->id, 'work_date' => '2026-11-10', 'active_seconds' => 2000])->assertOk();

        $this->assertSame(1, AttendanceDay::where('user_id', $emp->id)->count());
        $history = $this->device($c)->getJson('/api/attendance/days/'.$first->json('data.id').'/corrections')->assertOk()->json('data');
        $this->assertSame(['create', 'override'], array_column($history, 'kind'));
        $this->assertSame(1000, $history[1]['before']['active_seconds']);
        $this->assertSame(2000, $history[1]['after']['active_seconds']);
        $this->assertArrayHasKey('name', $history[0]['actor']);
    }

    public function test_a_correction_wins_over_later_measured_time(): void
    {
        $emp = $this->makeUser();
        $day = $this->day($emp, '2026-11-18');
        [, $c] = $this->signIn($this->makeUser(Role::ADMIN));
        $this->device($c)->postJson('/api/attendance/corrections', ['user_id' => $emp->id, 'work_date' => '2026-11-18', 'active_seconds' => 100])->assertOk();

        $day->fresh()->update(['computed_active_seconds' => 9999]); // more time measured afterwards

        $this->assertSame(100, $day->fresh()->activeSeconds());
    }

    public function test_correction_boundaries(): void
    {
        $emp = $this->makeUser();
        $admin = $this->makeUser(Role::ADMIN);
        $superUser = $this->makeUser(Role::SUPER_ADMIN);
        [, $c] = $this->signIn($admin);
        $post = fn (array $d) => $this->device($c)->postJson('/api/attendance/corrections', $d + ['user_id' => $emp->id, 'work_date' => '2026-11-10']);

        $post([])->assertStatus(422);                                                        // nothing to correct
        $post(['active_seconds' => -1])->assertStatus(422);
        $post(['active_seconds' => 86401])->assertStatus(422);
        $post(['active_seconds' => 10, 'work_date' => '2999-01-01'])->assertStatus(422);       // future
        $post(['active_seconds' => 10, 'work_date' => '10/11/2026'])->assertStatus(422);
        $post(['first_in' => '2026-11-10 05:00:00', 'last_out' => '2026-11-10 04:00:00'])->assertStatus(422); // out before in
        $post(['first_in' => '2026-11-12 01:00:00'])->assertStatus(422)->assertJsonPath('error.details.first_in.0', fn ($m) => str_contains($m, 'not on the selected day'));
        $this->device($c)->postJson('/api/attendance/corrections', ['user_id' => 99999, 'work_date' => '2026-11-10', 'active_seconds' => 10])->assertStatus(422);
        $this->assertSame(0, AttendanceCorrection::count());

        // Boundary of the business day: 16:00 UTC is already the next day in Manila.
        $post(['first_in' => '2026-11-10 16:00:00'])->assertStatus(422);
        $post(['first_in' => '2026-11-10 15:59:59'])->assertOk();
    }

    public function test_account_boundary_applies_to_corrections_and_self_correction_is_blocked(): void
    {
        $adminB = $this->makeUser(Role::ADMIN);
        $superUser = $this->makeUser(Role::SUPER_ADMIN);
        $admin = $this->makeUser(Role::ADMIN);
        [, $c] = $this->signIn($admin);
        $payload = fn (User $u) => ['user_id' => $u->id, 'work_date' => '2026-11-10', 'active_seconds' => 10];

        $this->device($c)->postJson('/api/attendance/corrections', $payload($adminB))->assertStatus(403);
        $this->device($c)->postJson('/api/attendance/corrections', $payload($superUser))->assertStatus(403);
        $this->device($c)->postJson('/api/attendance/corrections', $payload($admin))->assertStatus(422)->assertJsonPath('error.code', 'cannot_modify_self');
        $this->assertSame(0, AttendanceCorrection::count());

        // A Super Admin may correct an Admin.
        [, $sc] = $this->signIn($superUser);
        $this->device($sc)->postJson('/api/attendance/corrections', $payload($adminB))->assertOk();
        $this->device($sc)->postJson('/api/attendance/corrections', $payload($superUser))->assertStatus(422);
    }

    public function test_attendance_responses_never_leak_secrets(): void
    {
        $emp = $this->makeUser();
        $this->day($emp, '2026-11-18');
        [, $c] = $this->signIn($this->makeUser(Role::ADMIN));

        $body = $this->device($c)->getJson('/api/attendance?from=2026-11-18&to=2026-11-18')->getContent();

        foreach (['password', 'session_id', 'email'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
