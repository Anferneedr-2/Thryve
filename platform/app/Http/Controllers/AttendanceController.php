<?php

namespace App\Http\Controllers;

use App\Attendance\Tracker;
use App\Http\Responses\Api;
use App\Models\AttendanceDay;
use App\Support\BusinessDay;
use App\Updates\Maintenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's own attendance: heartbeat, close, and their own history. Never anyone else's. */
class AttendanceController extends Controller
{
    public function __construct(private Tracker $tracker) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $d = $request->validate(['activity_age_seconds' => ['nullable', 'integer', 'between:0,604800']]);
        $now = CarbonImmutable::now();

        // While an update or restore runs, the one browser allowed through (the updating Super Admin's) must not write:
        // a row added between the backup and its check would make the backup look incomplete. Time in maintenance is not
        // counted for that person.
        if (app(Maintenance::class)->active()) {
            return Api::ok([
                'state' => 'active', 'paused' => true,
                'interval_seconds' => (int) config('thryve.attendance.heartbeat_seconds'),
                'today' => $this->today($request->user()->id),
                'unread_notifications' => NotificationController::unreadFor($request->user()->id),
            ]);
        }
        $activityAt = isset($d['activity_age_seconds']) ? $now->subSeconds($d['activity_age_seconds']) : null;

        $session = $this->tracker->heartbeat($request->user(), $now, $activityAt);

        return Api::ok([
            'state' => $this->tracker->state($session, $now),
            'interval_seconds' => (int) config('thryve.attendance.heartbeat_seconds'),
            'today' => $this->today($request->user()->id),
            'unread_notifications' => NotificationController::unreadFor($request->user()->id),
        ]);
    }

    /** Sent when the page is being closed. Best effort; the scheduled sweep covers a missed message. */
    public function close(Request $request): JsonResponse
    {
        if (app(Maintenance::class)->active()) {
            return Api::ok(['closed' => false, 'paused' => true]);
        }
        $this->tracker->end($request->user(), CarbonImmutable::now(), 'closed');

        return Api::ok(['closed' => true]);
    }

    public function mine(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $f['to'] ?? BusinessDay::today();
        $from = $f['from'] ?? CarbonImmutable::parse($to)->subDays(29)->toDateString();
        if ($from > $to) {
            return Api::error('validation_failed', 'The start date is after the end date.', 422, ['from' => ['The start date is after the end date.']]);
        }

        $days = AttendanceDay::where('user_id', $request->user()->id)->whereBetween('work_date', [$from, $to])->orderByDesc('work_date')->get();

        return Api::ok(['days' => $days->map(fn ($d) => self::present($d))->values(), 'today' => $this->today($request->user()->id)], ['from' => $from, 'to' => $to, 'timezone' => BusinessDay::timezone()]);
    }

    private function today(int $userId): array
    {
        $day = AttendanceDay::where('user_id', $userId)->where('work_date', BusinessDay::today())->first();

        return ['work_date' => BusinessDay::today(), 'active_seconds' => $day?->activeSeconds() ?? 0, 'idle_seconds' => $day?->computed_idle_seconds ?? 0];
    }

    public static function present(AttendanceDay $d): array
    {
        return [
            'id' => $d->id,
            'user_id' => $d->user_id,
            'work_date' => $d->work_date->toDateString(),
            'first_in_at' => $d->firstIn()?->toIso8601String(),
            'last_out_at' => $d->lastOut()?->toIso8601String(),
            'active_seconds' => $d->activeSeconds(),
            'idle_seconds' => $d->computed_idle_seconds,
            'is_corrected' => $d->is_corrected,
        ];
    }
}
