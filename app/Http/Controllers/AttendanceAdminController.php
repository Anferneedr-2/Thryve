<?php

namespace App\Http\Controllers;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDay;
use App\Http\Responses\Api;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Company-wide attendance for Admin and Super Admin (route guard), plus corrections.
 * Corrections follow the account boundary (an Admin corrects Employees only), are audited, never notify anyone,
 * and cannot be made on your own record.
 */
class AttendanceAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $to = $f['to'] ?? BusinessDay::today();
        $from = $f['from'] ?? CarbonImmutable::parse($to)->subDays(6)->toDateString();
        if ($from > $to) {
            return Api::error('validation_failed', 'The start date is after the end date.', 422, ['from' => ['The start date is after the end date.']]);
        }

        $q = AttendanceDay::with('user:id,name,username')->whereBetween('work_date', [$from, $to])->orderByDesc('work_date')->orderBy('user_id');
        if (! empty($f['user_id'])) {
            $q->where('user_id', $f['user_id']);
        }
        if (! empty($f['department_id'])) {
            $q->whereIn('user_id', fn ($s) => $s->select('user_id')->from('department_user')->where('department_id', $f['department_id']));
        }
        $page = $q->paginate($f['per_page'] ?? 25);

        return Api::ok(
            $page->getCollection()->map(fn ($d) => AttendanceController::present($d) + ['user' => ['id' => $d->user->id, 'name' => $d->user->name, 'username' => $d->user->username]])->values(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(), 'from' => $from, 'to' => $to, 'timezone' => BusinessDay::timezone()],
        );
    }

    /** Create a missed day or override an existing one. Only the supplied fields change. */
    public function correct(Request $request): JsonResponse
    {
        $d = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'work_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDay::today()],
            'first_in' => ['nullable', 'date'], 'last_out' => ['nullable', 'date', 'after_or_equal:first_in'],
            'active_seconds' => ['nullable', 'integer', 'between:0,86400'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (! isset($d['first_in']) && ! isset($d['last_out']) && ! isset($d['active_seconds'])) {
            return Api::error('validation_failed', 'Provide at least one value to correct.', 422, ['active_seconds' => ['Provide at least one value to correct.']]);
        }

        $subject = User::findOrFail($d['user_id']);
        if ($subject->is($request->user())) {
            return Api::error('cannot_modify_self', 'You cannot correct your own attendance.', 422);
        }
        Gate::authorize('manage', $subject);

        // A time entered for a business day must fall on that day (Philippine time).
        foreach (['first_in', 'last_out'] as $field) {
            if (isset($d[$field]) && BusinessDay::dateOf(CarbonImmutable::parse($d[$field])) !== $d['work_date']) {
                return Api::error('validation_failed', 'That time is not on the selected day.', 422, [$field => ['That time is not on the selected day (Philippine time).']]);
            }
        }

        $day = DB::transaction(function () use ($d, $subject, $request) {
            DB::table('attendance_days')->insertOrIgnore(['user_id' => $subject->id, 'work_date' => $d['work_date'], 'created_at' => now(), 'updated_at' => now()]);
            $day = AttendanceDay::where('user_id', $subject->id)->where('work_date', $d['work_date'])->lockForUpdate()->firstOrFail();
            $existed = $day->wasRecentlyCreated || $day->computed_active_seconds > 0 || $day->computed_first_in_at || $day->is_corrected;
            $before = AttendanceController::present($day);

            $day->fill(array_filter([
                'active_seconds_override' => $d['active_seconds'] ?? null,
                'first_in_override' => isset($d['first_in']) ? CarbonImmutable::parse($d['first_in'])->utc() : null,
                'last_out_override' => isset($d['last_out']) ? CarbonImmutable::parse($d['last_out'])->utc() : null,
            ], fn ($v) => $v !== null));
            $day->is_corrected = true;
            $day->save();

            AttendanceCorrection::create([
                'attendance_day_id' => $day->id, 'user_id' => $subject->id, 'actor_id' => $request->user()->id,
                'kind' => $existed ? 'override' : 'create',
                'before' => $existed ? $before : null,
                'after' => AttendanceController::present($day->fresh()),
                'note' => $d['note'] ?? null,
            ]);
            Audit::log('attendance.corrected', $request->user(), $subject, ['work_date' => $d['work_date'], 'kind' => $existed ? 'override' : 'create']);

            return $day->fresh();
        });

        // Deliberately no notification to the employee or anyone else (approved rule).
        return Api::ok(AttendanceController::present($day));
    }

    public function history(AttendanceDay $day): JsonResponse
    {
        return Api::ok($day->corrections()->with('actor:id,name,username')->get()->map(fn ($c) => [
            'id' => $c->id, 'kind' => $c->kind, 'before' => $c->before, 'after' => $c->after, 'note' => $c->note,
            'at' => $c->created_at->toIso8601String(), 'actor' => ['id' => $c->actor->id, 'name' => $c->actor->name],
        ])->values());
    }
}
