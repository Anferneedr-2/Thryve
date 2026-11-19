<?php

namespace Tests\Feature;

use App\Attendance\Tracker;
use App\Models\AttendanceDay;
use App\Models\AttendanceInterval;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Time maths for attendance. All times are UTC; Philippine time is UTC+8, so 16:00 UTC is Manila midnight.
 * Default idle threshold is 30 minutes and the silence timeout is 150 seconds.
 */
class AttendanceTrackerTest extends TestCase
{
    use RefreshDatabase;

    private Tracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new Tracker;
    }

    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-11-19 {$utc}", 'UTC');
    }

    /** Heartbeats every $step seconds from $from to $to (inclusive); activity is reported at each beat while $active. */
    private function beats(User $u, string $from, string $to, int $step = 60, bool $active = true): void
    {
        for ($t = $this->at($from)->addSeconds($step); $t <= $this->at($to); $t = $t->addSeconds($step)) {
            $this->tracker->heartbeat($u, $t, $active ? $t : null);
        }
    }

    private function day(User $u, string $date): ?AttendanceDay
    {
        return AttendanceDay::where('user_id', $u->id)->where('work_date', $date)->first();
    }

    // ---- sign-in and active time ---------------------------------------------------------------

    public function test_sign_in_opens_a_session_and_the_day_with_no_time_yet(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));

        $day = $this->day($u, '2026-11-19');
        $this->assertSame(0, $day->computed_active_seconds);
        $this->assertSame('2026-11-19 01:00:00', $day->computed_first_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, WorkSession::where('user_id', $u->id)->whereNull('ended_at')->count());
    }

    public function test_continuous_activity_counts_as_active_time(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:10:00');

        $day = $this->day($u, '2026-11-19');
        $this->assertSame(600, $day->computed_active_seconds);
        $this->assertSame(0, $day->computed_idle_seconds);
    }

    // ---- idle ----------------------------------------------------------------------------------

    public function test_idle_time_is_recorded_separately_and_not_counted_as_active(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00')); // last activity: 01:00
        $this->beats($u, '01:00:00', '01:40:00', 120, active: false); // no activity at all for 40 minutes

        $day = $this->day($u, '2026-11-19');
        $this->assertSame(1800, $day->computed_active_seconds, 'only the 30 minutes after the last activity count');
        $this->assertSame(600, $day->computed_idle_seconds);
        $this->assertSame(1800 + 600, (int) AttendanceInterval::where('user_id', $u->id)->sum('seconds'), 'no gaps and no double counting');
    }

    public function test_idle_never_ends_the_session(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '03:00:00', 120, active: false); // two hours with nothing but heartbeats

        $this->assertNull(WorkSession::where('user_id', $u->id)->first()->ended_at);
    }

    public function test_resuming_after_idle_starts_counting_again_from_the_new_activity(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:48:00', 120, active: false); // active 30 min, then idle (heartbeats keep flowing)
        $this->tracker->heartbeat($u, $this->at('01:50:00'), $this->at('01:50:00')); // idle until the moment of new activity
        $this->tracker->heartbeat($u, $this->at('01:52:00'), null); // two minutes inside the new active window

        $day = $this->day($u, '2026-11-19');
        $this->assertSame(1800 + 120, $day->computed_active_seconds);
        $this->assertSame(20 * 60, $day->computed_idle_seconds, 'idle from 01:30 until the activity at 01:50');
        $this->assertSame('active', $this->tracker->state(WorkSession::first(), $this->at('01:52:00')));
    }

    public function test_activity_in_the_middle_of_a_gap_splits_it_correctly(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:28:00', 120, active: false); // still within the first window
        // Silent until 01:31 (3 minutes = timeout is 150s, so do it in two beats), activity at 01:30:30
        $this->tracker->heartbeat($u, $this->at('01:29:30'), null);
        $this->tracker->heartbeat($u, $this->at('01:31:00'), $this->at('01:30:30'));

        // 01:00-01:30 active, 01:30-01:30:30 idle, 01:30:30-01:31 active
        $day = $this->day($u, '2026-11-19');
        $this->assertSame(1800 + 30, $day->computed_active_seconds);
        $this->assertSame(30, $day->computed_idle_seconds);
    }

    public function test_the_idle_threshold_comes_from_settings(): void
    {
        Setting::create(['key' => 'idle_minutes', 'value' => 5]);
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:10:00', 60, active: false);

        $day = $this->day($u, '2026-11-19');
        $this->assertSame(300, $day->computed_active_seconds);
        $this->assertSame(300, $day->computed_idle_seconds);
    }

    // ---- closing -------------------------------------------------------------------------------

    public function test_sign_out_counts_time_up_to_the_moment_and_closes_the_session(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:10:00');
        $this->tracker->end($u, $this->at('01:10:45'), 'logout');

        $s = WorkSession::first();
        $this->assertSame('logout', $s->end_reason);
        $this->assertSame('01:10:45', $s->ended_at->format('H:i:s'));
        $day = $this->day($u, '2026-11-19');
        $this->assertSame(645, $day->computed_active_seconds + $day->computed_idle_seconds);
        $this->assertSame('01:10:45', $day->computed_last_out_at->format('H:i:s'));
    }

    public function test_browser_close_message_after_a_silent_gap_does_not_invent_time(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:05:00');
        $this->tracker->end($u, $this->at('02:00:00'), 'closed'); // the message arrives an hour late

        $s = WorkSession::first();
        $this->assertSame('timeout', $s->end_reason);
        $this->assertSame('01:05:00', $s->ended_at->format('H:i:s'));
        $this->assertSame(300, $this->day($u, '2026-11-19')->computed_active_seconds);
    }

    public function test_heartbeat_after_a_long_silence_closes_the_old_session_and_starts_a_new_one(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:10:00');
        $this->tracker->heartbeat($u, $this->at('03:00:00'), $this->at('03:00:00')); // reconnect two hours later
        $this->beats($u, '03:00:00', '03:02:00');

        $sessions = WorkSession::orderBy('id')->get();
        $this->assertCount(2, $sessions);
        $this->assertSame('timeout', $sessions[0]->end_reason);
        $this->assertSame('01:10:00', $sessions[0]->ended_at->format('H:i:s'));
        $this->assertNull($sessions[1]->ended_at);
        $this->assertSame(600 + 120, $this->day($u, '2026-11-19')->computed_active_seconds, 'the 1h50m gap is not counted');
        $this->assertSame(1, AttendanceDay::where('user_id', $u->id)->count(), 'both sessions roll up into one day row');
    }

    public function test_the_sweep_closes_only_sessions_that_went_silent(): void
    {
        [$quiet, $busy] = [User::factory()->create(), User::factory()->create()];
        $this->tracker->start($quiet, $this->at('01:00:00'));
        $this->tracker->start($busy, $this->at('01:00:00'));
        $this->tracker->heartbeat($busy, $this->at('01:03:00'), $this->at('01:03:00'));

        $closed = $this->tracker->reconcile($this->at('01:04:00'));

        $this->assertSame(1, $closed);
        $this->assertSame('timeout', WorkSession::where('user_id', $quiet->id)->first()->end_reason);
        $this->assertSame('01:00:00', WorkSession::where('user_id', $quiet->id)->first()->ended_at->format('H:i:s'));
        $this->assertNull(WorkSession::where('user_id', $busy->id)->first()->ended_at);
        $this->assertSame(0, $this->tracker->reconcile($this->at('01:04:00')), 'running it again changes nothing');
    }

    public function test_cut_ends_the_session_at_the_last_heartbeat_without_counting_more(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:05:00');
        $this->tracker->cut($u, 'terminated');

        $s = WorkSession::first();
        $this->assertSame('terminated', $s->end_reason);
        $this->assertSame('01:05:00', $s->ended_at->format('H:i:s'));
        $this->assertSame(300, $this->day($u, '2026-11-19')->computed_active_seconds);
    }

    // ---- replacement, several tabs, repeated sign-ins -------------------------------------------

    public function test_signing_in_again_replaces_the_open_session(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:05:00');
        $this->tracker->start($u, $this->at('01:06:00')); // signed in on another device

        $sessions = WorkSession::orderBy('id')->get();
        $this->assertSame('replaced', $sessions[0]->end_reason);
        $this->assertSame('01:05:00', $sessions[0]->ended_at->format('H:i:s'));
        $this->assertSame(1, WorkSession::whereNull('ended_at')->count());
        $this->assertSame(300, $this->day($u, '2026-11-19')->computed_active_seconds);
    }

    public function test_multiple_sessions_the_same_day_accumulate_into_one_daily_rollup(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:10:00');
        $this->tracker->end($u, $this->at('01:10:00'), 'logout');
        $this->tracker->start($u, $this->at('05:00:00'));
        $this->beats($u, '05:00:00', '05:20:00');
        $this->tracker->end($u, $this->at('05:20:00'), 'logout');

        $this->assertSame(1, AttendanceDay::where('user_id', $u->id)->count());
        $day = $this->day($u, '2026-11-19');
        $this->assertSame(600 + 1200, $day->computed_active_seconds);
        $this->assertSame('01:00:00', $day->computed_first_in_at->format('H:i:s'));
        $this->assertSame('05:20:00', $day->computed_last_out_at->format('H:i:s'));
    }

    public function test_the_database_refuses_a_second_row_for_the_same_person_and_day(): void
    {
        $u = User::factory()->create();
        AttendanceDay::create(['user_id' => $u->id, 'work_date' => '2026-11-19']);

        $this->expectException(QueryException::class);
        AttendanceDay::create(['user_id' => $u->id, 'work_date' => '2026-11-19']);
    }

    public function test_several_tabs_and_out_of_order_heartbeats_do_not_double_count(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->tracker->heartbeat($u, $this->at('01:01:00'), $this->at('01:01:00')); // tab A
        $this->tracker->heartbeat($u, $this->at('01:01:00'), $this->at('01:00:59')); // tab B, same second
        $this->tracker->heartbeat($u, $this->at('01:00:30'), $this->at('01:00:30')); // a late message from tab B
        $this->tracker->heartbeat($u, $this->at('01:02:00'), $this->at('01:02:00'));

        $this->assertSame(120, $this->day($u, '2026-11-19')->computed_active_seconds);
        $this->assertSame(120, (int) AttendanceInterval::where('user_id', $u->id)->sum('seconds'));
    }

    public function test_signing_in_during_an_idle_period_keeps_the_idle_time_already_recorded(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:40:00', 120, active: false); // idle since 01:30
        $this->tracker->start($u, $this->at('01:45:00')); // signs in again from another device while idle

        $first = WorkSession::orderBy('id')->first();
        $this->assertSame('replaced', $first->end_reason);
        $this->assertSame('01:40:00', $first->ended_at->format('H:i:s'));
        $day = $this->day($u, '2026-11-19');
        $this->assertSame(1800, $day->computed_active_seconds);
        $this->assertSame(600, $day->computed_idle_seconds);
        $this->assertSame(1, WorkSession::whereNull('ended_at')->count());
    }

    // ---- Philippine midnight -------------------------------------------------------------------

    public function test_time_is_split_at_philippine_midnight(): void
    {
        $u = User::factory()->create();
        // 15:58 UTC = 23:58 Manila on 19 Nov. Manila midnight is 16:00 UTC.
        $this->tracker->start($u, $this->at('15:58:00'));
        $this->beats($u, '15:58:00', '16:03:00');

        $before = $this->day($u, '2026-11-19');
        $after = $this->day($u, '2026-11-20');
        $this->assertSame(120, $before->computed_active_seconds);
        $this->assertSame(180, $after->computed_active_seconds);
        $this->assertSame('2026-11-19 16:00:00', $before->computed_last_out_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-19 16:00:00', $after->computed_first_in_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, AttendanceInterval::where('user_id', $u->id)->where('started_at', '<', $this->at('16:00:00'))->where('ended_at', '>', $this->at('16:00:00'))->count(), 'no interval straddles midnight');
    }

    public function test_a_single_long_gap_across_midnight_is_split_too(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('15:59:00'));
        $this->tracker->heartbeat($u, $this->at('16:01:00'), $this->at('16:01:00')); // 120s beat spanning midnight

        $this->assertSame(60, $this->day($u, '2026-11-19')->computed_active_seconds);
        $this->assertSame(60, $this->day($u, '2026-11-20')->computed_active_seconds);
    }

    public function test_the_business_date_not_the_utc_date_decides_the_day(): void
    {
        $u = User::factory()->create();
        // 20:00 UTC on the 19th is 04:00 Manila on the 20th.
        $this->tracker->start($u, $this->at('20:00:00'));
        $this->beats($u, '20:00:00', '20:02:00');

        $this->assertNull($this->day($u, '2026-11-19'));
        $this->assertSame(120, $this->day($u, '2026-11-20')->computed_active_seconds);
    }

    // ---- invariants ----------------------------------------------------------------------------

    public function test_intervals_never_overlap_and_total_equals_elapsed_time(): void
    {
        $u = User::factory()->create();
        $this->tracker->start($u, $this->at('01:00:00'));
        $this->beats($u, '01:00:00', '01:20:00', 60, active: true);
        $this->beats($u, '01:20:00', '02:20:00', 120, active: false);
        $this->tracker->heartbeat($u, $this->at('02:22:00'), $this->at('02:22:00'));
        $this->tracker->end($u, $this->at('02:24:00'), 'logout');

        $rows = AttendanceInterval::where('user_id', $u->id)->orderBy('started_at')->get();
        $this->assertGreaterThan(5, $rows->count());
        for ($i = 1; $i < $rows->count(); $i++) {
            $this->assertSame($rows[$i - 1]->ended_at->getTimestamp(), $rows[$i]->started_at->getTimestamp(), 'interval '.$i.' starts where the previous ended');
        }
        $this->assertSame($this->at('02:24:00')->getTimestamp() - $this->at('01:00:00')->getTimestamp(), (int) $rows->sum('seconds'));
        $day = $this->day($u, '2026-11-19');
        $this->assertSame((int) $rows->where('kind', 'active')->sum('seconds'), $day->computed_active_seconds);
        $this->assertSame((int) $rows->where('kind', 'idle')->sum('seconds'), $day->computed_idle_seconds);
    }

    public function test_state_reports_active_idle_or_offline(): void
    {
        $u = User::factory()->create();
        $s = $this->tracker->start($u, $this->at('01:00:00'));

        $this->assertSame('active', $this->tracker->state($s->fresh(), $this->at('01:00:10')));
        $this->assertSame('offline', $this->tracker->state($s->fresh(), $this->at('01:10:00')), 'no heartbeat for over 150s');

        $this->beats($u, '01:00:00', '01:40:00', 120, active: false);
        $this->assertSame('idle', $this->tracker->state(WorkSession::first(), $this->at('01:40:30')));
        $this->tracker->end($u, $this->at('01:41:00'), 'logout');
        $this->assertSame('offline', $this->tracker->state(WorkSession::first(), $this->at('01:41:10')));
    }
}
