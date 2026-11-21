<?php

namespace App\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceInterval;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\BusinessDay;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turns sign-in, heartbeats and sign-out into the official attendance record.
 *
 * Model: a work session is open from sign-in until it ends. Between two heartbeats the time is split into
 * ACTIVE (within the idle threshold of the last user activity) and IDLE (everything else). Idle time is stored
 * but never counted as work. Idle never signs anyone out. Every stretch is split at Philippine midnight so each
 * business day gets exactly its own seconds.
 */
class Tracker
{
    public function idleSeconds(): int
    {
        return (int) Settings::get('idle_minutes') * 60;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('thryve.attendance.timeout_seconds');
    }

    private function t(CarbonInterface $t): CarbonImmutable
    {
        return CarbonImmutable::instance($t)->utc()->startOfSecond();
    }

    private function openSession(User $user): ?WorkSession
    {
        $open = WorkSession::where('user_id', $user->id)->whereNull('ended_at')->orderBy('id')->lockForUpdate()->get();
        // At most one session can be open. If a crash ever left more, keep the newest and close the others.
        foreach ($open->slice(0, max(0, $open->count() - 1)) as $stale) {
            $this->finish($stale, $this->t($stale->last_seen_at), 'timeout');
        }

        return $open->last();
    }

    /** Sign-in: the new session replaces any open one (single active session). */
    public function start(User $user, CarbonInterface $now): WorkSession
    {
        $now = $this->t($now);

        return DB::transaction(function () use ($user, $now) {
            if ($previous = $this->openSession($user)) {
                $this->finish($previous, $this->t($previous->last_seen_at), 'replaced');
            }
            $session = WorkSession::create(['user_id' => $user->id, 'started_at' => $now, 'last_seen_at' => $now, 'last_activity_at' => $now]);
            $day = $this->day($user->id, BusinessDay::dateOf($now));
            if (! $day->computed_first_in_at || $now < $day->computed_first_in_at) {
                $day->update(['computed_first_in_at' => $now]);
            }

            return $session;
        });
    }

    /**
     * A heartbeat from the browser. $activityAt is when the person last touched the page (null = unchanged).
     * Out-of-order or duplicate heartbeats (several tabs) are harmless.
     */
    public function heartbeat(User $user, CarbonInterface $now, ?CarbonInterface $activityAt = null): WorkSession
    {
        $now = $this->t($now);
        $activityAt = $activityAt ? $this->t($activityAt) : null;

        return DB::transaction(function () use ($user, $now, $activityAt) {
            $session = $this->openSession($user);

            if ($session && $now->getTimestamp() - $this->t($session->last_seen_at)->getTimestamp() > $this->timeoutSeconds()) {
                // Silent for too long: treat the session as closed when we last heard from it, then start a new one.
                $this->finish($session, $this->t($session->last_seen_at), 'timeout');
                $session = null;
            }
            if (! $session) {
                $session = WorkSession::create(['user_id' => $user->id, 'started_at' => $now, 'last_seen_at' => $now, 'last_activity_at' => $activityAt ?? $now]);
                $day = $this->day($user->id, BusinessDay::dateOf($now));
                if (! $day->computed_first_in_at || $now < $day->computed_first_in_at) {
                    $day->update(['computed_first_in_at' => $now]);
                }

                return $session;
            }

            $this->account($session, $now, $activityAt);

            return $session->fresh();
        });
    }

    /** Sign-out or browser close. Closes the session now (counting the time up to now), unless it had already gone silent. */
    public function end(User $user, CarbonInterface $now, string $reason): ?WorkSession
    {
        $now = $this->t($now);

        return DB::transaction(function () use ($user, $now, $reason) {
            $session = $this->openSession($user);
            if (! $session) {
                return null;
            }
            $silent = $now->getTimestamp() - $this->t($session->last_seen_at)->getTimestamp() > $this->timeoutSeconds();
            if ($silent) {
                $this->finish($session, $this->t($session->last_seen_at), 'timeout');
            } else {
                $this->account($session, $now, null);
                $this->finish($session->fresh(), $now, $reason);
            }

            return $session->fresh();
        });
    }

    /** End a session at its last heartbeat without counting more time (terminated, deactivated, replaced). */
    public function cut(User $user, string $reason): ?WorkSession
    {
        return DB::transaction(function () use ($user, $reason) {
            $session = $this->openSession($user);
            if ($session) {
                $this->finish($session, $this->t($session->last_seen_at), $reason);
            }

            return $session?->fresh();
        });
    }

    /** Scheduled sweep: close sessions that went silent (browser closed without telling us). */
    public function reconcile(CarbonInterface $now): int
    {
        $cutoff = $this->t($now)->subSeconds($this->timeoutSeconds());
        $closed = 0;

        WorkSession::whereNull('ended_at')->where('last_seen_at', '<', $cutoff)->pluck('id')->each(function ($id) use (&$closed) {
            DB::transaction(function () use ($id, &$closed) {
                $session = WorkSession::whereKey($id)->whereNull('ended_at')->lockForUpdate()->first();
                if ($session) {
                    $this->finish($session, $this->t($session->last_seen_at), 'timeout');
                    $closed++;
                }
            });
        });

        return $closed;
    }

    /** Attendance bookkeeping must never block signing in or out: log the problem and carry on. */
    public function safely(callable $fn): void
    {
        try {
            $fn($this);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Current state for display: active, idle or offline. */
    public function state(WorkSession $session, CarbonInterface $now): string
    {
        $now = $this->t($now);
        if ($session->ended_at || $now->getTimestamp() - $this->t($session->last_seen_at)->getTimestamp() > $this->timeoutSeconds()) {
            return 'offline';
        }

        return $this->t($session->last_activity_at)->addSeconds($this->idleSeconds()) > $now ? 'active' : 'idle';
    }

    // ---- internals -----------------------------------------------------------------------------

    private function finish(WorkSession $session, CarbonImmutable $at, string $reason): void
    {
        $session->update(['ended_at' => $at, 'end_reason' => $reason, 'last_seen_at' => $at]);
        // Time out of the last day touched, so the record shows when the person left.
        $last = $this->t($session->last_seen_at);
        $day = $this->day($session->user_id, BusinessDay::dateOf($last));
        if (! $day->computed_last_out_at || $day->computed_last_out_at < $last) {
            $day->update(['computed_last_out_at' => $last]);
        }
    }

    /** Attribute the time between the last heartbeat and $to as active or idle. */
    private function account(WorkSession $session, CarbonImmutable $to, ?CarbonImmutable $activityAt): void
    {
        $from = $this->t($session->last_seen_at);
        $previous = $this->t($session->last_activity_at);
        $latest = $activityAt ? $activityAt->max($previous)->min($to) : $previous;

        if ($to <= $from) {
            // Late or duplicate heartbeat: nothing to count, but remember newer activity.
            $session->update(['last_activity_at' => $latest->max($previous)]);

            return;
        }

        $threshold = $this->idleSeconds();
        $windows = [[$previous, $previous->addSeconds($threshold)]];
        if ($latest > $previous) {
            $windows[] = [$latest, $latest->addSeconds($threshold)];
        }

        // Clip the "recently active" windows to this period and merge overlaps.
        $active = [];
        foreach ($windows as [$start, $end]) {
            $start = $start->max($from);
            $end = $end->min($to);
            if ($end <= $start) {
                continue;
            }
            if ($active && $start <= $active[array_key_last($active)][1]) {
                $active[array_key_last($active)][1] = $end->max($active[array_key_last($active)][1]);
            } else {
                $active[] = [$start, $end];
            }
        }

        $cursor = $from;
        foreach ($active as [$start, $end]) {
            if ($start > $cursor) {
                $this->record($session, 'idle', $cursor, $start);
            }
            $this->record($session, 'active', $start, $end);
            $cursor = $end;
        }
        if ($cursor < $to) {
            $this->record($session, 'idle', $cursor, $to);
        }

        $session->update(['last_seen_at' => $to, 'last_activity_at' => $latest]);
    }

    /** Store one stretch, split at Philippine midnight, and add it to each day's rollup. */
    private function record(WorkSession $session, string $kind, CarbonImmutable $start, CarbonImmutable $end): void
    {
        while ($start < $end) {
            $date = BusinessDay::dateOf($start);
            $pieceEnd = CarbonImmutable::instance(BusinessDay::endUtc($date))->min($end);
            $seconds = $pieceEnd->getTimestamp() - $start->getTimestamp();

            AttendanceInterval::create([
                'work_session_id' => $session->id, 'user_id' => $session->user_id, 'work_date' => $date,
                'kind' => $kind, 'started_at' => $start, 'ended_at' => $pieceEnd, 'seconds' => $seconds,
            ]);

            $day = $this->day($session->user_id, $date);
            $day->update([
                $kind === 'active' ? 'computed_active_seconds' : 'computed_idle_seconds' => $day->{$kind === 'active' ? 'computed_active_seconds' : 'computed_idle_seconds'} + $seconds,
                'computed_first_in_at' => $day->computed_first_in_at && $day->computed_first_in_at <= $start ? $day->computed_first_in_at : $start,
                'computed_last_out_at' => $day->computed_last_out_at && $day->computed_last_out_at >= $pieceEnd ? $day->computed_last_out_at : $pieceEnd,
            ]);

            $start = $pieceEnd;
        }
    }

    /** The one row for this person and business date, created on first use, locked for the transaction. */
    private function day(int $userId, string $date): AttendanceDay
    {
        DB::table('attendance_days')->insertOrIgnore(['user_id' => $userId, 'work_date' => $date, 'created_at' => now(), 'updated_at' => now()]);

        return AttendanceDay::where('user_id', $userId)->where('work_date', $date)->lockForUpdate()->firstOrFail();
    }
}
