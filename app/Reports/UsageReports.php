<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

final class UsageReports
{
    public function data(ReportFilters $f): array
    {
        $base = ReportQueries::usage($f)->where('status', '!=', 'pending');
        $tokens = (clone $base)->selectRaw('COALESCE(SUM(input_tokens),0) input_tokens, COALESCE(SUM(output_tokens),0) output_tokens, COUNT(*) attempts, COALESCE(SUM(is_estimate=1),0) estimated_attempts, COALESCE(SUM(cost IS NULL OR currency IS NULL),0) unpriced_attempts')->first();
        $answerCosts = (clone $base)->where('status', 'success')->whereNotNull('cost')->whereNotNull('currency')->select('currency')->selectRaw('AVG(cost) average_cost, COUNT(*) samples, SUM(is_estimate=1) estimated_attempts')->groupBy('currency')->orderBy('currency')->get();
        $byDay = (clone $base)->select('work_date')->selectRaw('SUM(input_tokens) input_tokens, SUM(output_tokens) output_tokens')->groupBy('work_date')->get()->keyBy('work_date');
        $daily = [];
        $recent = [];
        foreach (CarbonPeriod::create($f->from, $f->to) as $date) {
            $r = $byDay[$date->toDateString()] ?? null;
            $in = (int) ($r->input_tokens ?? 0);
            $out = (int) ($r->output_tokens ?? 0);
            $recent[] = $in + $out;
            if (count($recent) > 7) {
                array_shift($recent);
            }
            $daily[] = ['day' => $date->toDateString(), 'input_tokens' => $in, 'output_tokens' => $out, 'tokens' => $in + $out, 'moving_average' => round(array_sum($recent) / count($recent), 2)];
        }
        $dailyCosts = (clone $base)->whereNotNull('cost')->whereNotNull('currency')->select('work_date as day', 'currency')->selectRaw('SUM(cost) amount, SUM(is_estimate=1) estimated_requests')->groupBy('work_date', 'currency')->orderBy('work_date')->get();
        $departments = (clone $base)->join('department_user as du', 'du.user_id', '=', 'usage_records.user_id')->join('departments as d', 'd.id', '=', 'du.department_id')
            ->when($f->departmentId, fn ($q) => $q->where('d.id', $f->departmentId))->whereNotNull('cost')->whereNotNull('currency')
            ->select('d.id', 'd.name', 'currency')->selectRaw('SUM(cost) amount, SUM(input_tokens+output_tokens) tokens')->groupBy('d.id', 'd.name', 'currency')->orderBy('d.name')->get();
        $spenders = (clone $base)->join('users as u', 'u.id', '=', 'usage_records.user_id')->whereNotNull('cost')->whereNotNull('currency')
            ->select('u.id', 'u.name', 'currency')->selectRaw('SUM(cost) amount')->groupBy('u.id', 'u.name', 'currency')->orderByDesc('amount')->limit(30)->get();
        $conversationUsage = (clone $base)->whereNotNull('conversation_id')->select('conversation_id')->selectRaw('SUM(input_tokens+output_tokens) tokens')->groupBy('conversation_id');
        $buckets = [];
        foreach ([['Under 1k', 0, 1000], ['1–2k', 1000, 2000], ['2–5k', 2000, 5000], ['5–10k', 5000, 10000], ['10–20k', 10000, 20000], ['20k+', 20000, null]] as [$name, $min, $max]) {
            $q = DB::query()->fromSub(clone $conversationUsage, 'cu')->where('tokens', '>=', $min);
            if ($max !== null) {
                $q->where('tokens', '<', $max);
            }
            $buckets[] = ['name' => $name, 'conversations' => $q->count()];
        }
        $top = (clone $base)->join('conversations as c', 'c.id', '=', 'usage_records.conversation_id')->join('users as u', 'u.id', '=', 'usage_records.user_id')->whereNotNull('cost')->whereNotNull('currency')
            ->select('c.id', 'c.title', 'u.name', 'currency')->selectRaw('SUM(cost) amount, SUM(input_tokens+output_tokens) tokens')->groupBy('c.id', 'c.title', 'u.name', 'currency')->orderByDesc('amount')->limit(20)->get();
        $date = CarbonImmutable::parse($f->to);
        $month = new ReportFilters($date->startOfMonth()->toDateString(), $f->to, $f->userId, $f->departmentId);
        $monthCosts = ReportQueries::usage($month)->where('status', '!=', 'pending')->whereNotNull('cost')->whereNotNull('currency')->select('currency')
            ->selectRaw('SUM(cost) amount, SUM(is_estimate=1) estimated_requests')->groupBy('currency')->orderBy('currency')->get()->map(fn ($r) => ['currency' => $r->currency, 'amount' => (string) $r->amount,
                'projected' => round((float) $r->amount / $date->day * $date->daysInMonth, 6), 'estimated_requests' => (int) $r->estimated_requests])->all();

        return ['totals' => ['input_tokens' => (int) $tokens->input_tokens, 'output_tokens' => (int) $tokens->output_tokens, 'attempts' => (int) $tokens->attempts,
            'tokens_per_attempt' => $tokens->attempts ? round(($tokens->input_tokens + $tokens->output_tokens) / $tokens->attempts, 2) : null],
            'coverage' => ['estimated_attempts' => (int) $tokens->estimated_attempts, 'unpriced_attempts' => (int) $tokens->unpriced_attempts], 'answer_costs' => $answerCosts,
            'daily' => $daily, 'daily_costs' => $dailyCosts, 'departments' => $departments, 'spenders' => $spenders, 'buckets' => $buckets, 'top_conversations' => $top,
            'month' => $monthCosts, 'month_from' => $month->from, 'month_to' => $month->to,
            'notes' => ['Department subtotals overlap for people in multiple departments. Unassigned people are not in department groups.', 'Costs and projections use known historical prices; currencies are not added together. Projections are extrapolations, not forecasts.', 'Top-spender and conversation lists show up to 30 and 20 currency-specific rows respectively.']];
    }
}
