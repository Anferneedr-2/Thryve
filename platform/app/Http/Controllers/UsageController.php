<?php

namespace App\Http\Controllers;

use App\Ai\Allowance;
use App\Http\Responses\Api;
use App\Models\TokenGrant;
use App\Models\User;
use App\Support\Audit;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UsageController extends Controller
{
    /** The signed-in person's own allowance. No provider or account details are ever included. */
    public function mine(Request $request, Allowance $allowance): JsonResponse
    {
        return Api::ok($allowance->summary($request->user()));
    }

    /** Admin and Super Admin: usage by provider over a range of Philippine business days. */
    public function summary(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $to = $f['to'] ?? BusinessDay::today();
        $from = $f['from'] ?? CarbonImmutable::parse($to)->subDays(6)->toDateString();
        if ($from > $to) {
            return Api::error('validation_failed', 'The start date is after the end date.', 422, ['from' => ['The start date is after the end date.']]);
        }

        $rows = DB::table('usage_records as u')->join('provider_connections as p', 'p.id', '=', 'u.provider_connection_id')
            ->whereBetween('u.work_date', [$from, $to])->where('u.status', '!=', 'pending')
            ->groupBy('p.id', 'p.name', 'p.kind', 'u.currency')
            ->selectRaw("p.id, p.name, p.kind, u.currency, COUNT(*) as requests, SUM(u.status = 'failed') as failures, SUM(u.is_estimate) as estimates,
                SUM(u.input_tokens) as input_tokens, SUM(u.output_tokens) as output_tokens, SUM(u.cost) as cost, SUM(u.cost IS NULL) as unpriced, ROUND(AVG(u.latency_ms)) as avg_latency_ms")
            ->orderBy('p.name')->get();

        return Api::ok($rows->map(fn ($r) => [
            'provider_id' => $r->id, 'name' => $r->name, 'kind' => $r->kind, 'currency' => $r->currency, 'requests' => (int) $r->requests, 'failures' => (int) $r->failures,
            'estimated_requests' => (int) $r->estimates, 'unpriced_requests' => (int) $r->unpriced, 'input_tokens' => (int) $r->input_tokens, 'output_tokens' => (int) $r->output_tokens,
            'cost' => $r->cost === null ? null : number_format((float) $r->cost, 6, '.', ''), 'avg_latency_ms' => $r->avg_latency_ms === null ? null : (int) $r->avg_latency_ms,
        ])->values(), ['from' => $from, 'to' => $to, 'timezone' => BusinessDay::timezone()]);
    }

    /** Give one person extra tokens for today. Follows the account boundary: an Admin can do this for Employees. */
    public function grant(Request $request, User $user, Allowance $allowance): JsonResponse
    {
        Gate::authorize('manage', $user);
        $d = $request->validate(['tokens' => ['required', 'integer', 'between:1,100000000'], 'note' => ['nullable', 'string', 'max:200']]);
        $date = BusinessDay::today();
        TokenGrant::create(['user_id' => $user->id, 'work_date' => $date, 'tokens' => $d['tokens'], 'note' => $d['note'] ?? null, 'granted_by' => $request->user()->id]);
        Audit::log('tokens.granted', $request->user(), $user, ['tokens' => $d['tokens'], 'work_date' => $date]);

        return Api::ok($allowance->summary($user, $date));
    }
}
