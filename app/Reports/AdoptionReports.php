<?php

namespace App\Reports;

use App\Ai\SensitiveData;
use App\Support\BusinessDay;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

final class AdoptionReports
{
    private function agents(): mixed
    {
        return DB::table('agents')->whereNull('removed_at')->where('is_enabled', true)->whereNotNull('outbound_key');
    }

    private function questions(ReportFilters $f): mixed
    {
        return ReportQueries::messages($f, 'user')->select('c.user_id', 'c.agent_id')->selectRaw('COUNT(*) questions')->groupBy('c.user_id', 'c.agent_id');
    }

    public function data(ReportFilters $f, int $page = 1, int $agentPage = 1): array
    {
        $end = CarbonImmutable::parse($f->to);
        $active = [];
        foreach (['end_date' => 1, 'last_7_days' => 7, 'last_30_days' => 30] as $key => $days) {
            $range = new ReportFilters($end->subDays($days - 1)->toDateString(), $f->to, $f->userId, $f->departmentId);
            $active[$key] = ReportQueries::messages($range, 'user')->distinct()->count('c.user_id');
        }
        $active['daily_monthly_ratio'] = $active['last_30_days'] ? round($active['end_date'] / $active['last_30_days'] * 100, 2) : null;
        $recordedDays = ReportQueries::messages($f, 'user')->selectRaw(ReportQueries::day().' day, COUNT(DISTINCT c.user_id) people')->groupBy('day')->orderBy('day')->get()->keyBy('day');
        $daily = [];
        foreach (CarbonPeriod::create($f->from, $f->to) as $date) {
            $day = $date->toDateString();
            $daily[] = ['day' => $day, 'people' => (int) ($recordedDays[$day]->people ?? 0)];
        }
        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        $cells = ReportQueries::messages($f, 'user')->selectRaw("DAYOFWEEK(CONVERT_TZ(m.created_at,'+00:00','+08:00'))-1 weekday, HOUR(CONVERT_TZ(m.created_at,'+00:00','+08:00')) hour, COUNT(*) questions")->groupBy('weekday', 'hour')->get();
        foreach ($cells as $cell) {
            $heat[(int) $cell->weekday][(int) $cell->hour] = (int) $cell->questions;
        }
        $activity = ReportQueries::messages($f, 'user')->select('c.user_id')->selectRaw('COUNT(*) questions, COUNT(DISTINCT '.ReportQueries::day().') active_days')->groupBy('c.user_id');
        $current = ReportQueries::users($f)->leftJoinSub(clone $activity, 'activity', 'activity.user_id', '=', 'u.id');
        $counts = (clone $current)->selectRaw('COUNT(*) accounts, COALESCE(SUM(activity.questions IS NULL),0) unused, COALESCE(SUM(activity.active_days=1),0) one_day')->first();
        $unused = (clone $current)->whereNull('activity.questions')->orderBy('u.name')->limit(30)->get(['u.id', 'u.name', 'u.is_active']);
        $people = (clone $current)->select('u.id', 'u.name', 'u.is_active')->selectRaw('COALESCE(activity.questions,0) questions, COALESCE(activity.active_days,0) active_days')->orderBy('u.name')->orderBy('u.id')->paginate(10, ['*'], 'page', $page);
        $agentList = $this->agents()->orderBy('name')->orderBy('id')->paginate(8, ['id', 'name', 'is_default'], 'agent_page', $agentPage);
        $grants = DB::table('agent_user')->whereIn('user_id', $people->pluck('id'))->whereIn('agent_id', $agentList->pluck('id'))->get(['user_id', 'agent_id'])->keyBy(fn ($r) => $r->user_id.':'.$r->agent_id);
        $roles = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->whereIn('users.id', $people->pluck('id'))->pluck('roles.slug', 'users.id');
        $questionCounts = $this->questions($f)->whereIn('c.user_id', $people->pluck('id'))->get()->keyBy(fn ($r) => $r->user_id.':'.($r->agent_id ?? 0));
        $matrix = $people->getCollection()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'questions' => (int) $u->questions, 'active_days' => (int) $u->active_days, 'is_active' => (bool) $u->is_active,
            'cells' => $agentList->getCollection()->map(fn ($a) => ['allowed' => (bool) ($u->is_active && ($a->is_default || in_array($roles[$u->id] ?? '', ['admin', 'super_admin']) || isset($grants[$u->id.':'.$a->id]))), 'questions' => (int) ($questionCounts[$u->id.':'.$a->id]->questions ?? 0)])->values()->all()])->values()->all();
        $pairs = ReportQueries::users($f)->join('roles as r', 'r.id', '=', 'u.role_id')->where('r.slug', 'employee')->where('u.is_active', true)
            ->crossJoinSub($this->agents()->select('id', 'is_default'), 'a')->where(fn ($q) => $q->where('a.is_default', true)->orWhereExists(fn ($g) => $g->selectRaw('1')->from('agent_user as g')->whereColumn('g.user_id', 'u.id')->whereColumn('g.agent_id', 'a.id')))
            ->leftJoinSub($this->questions($f), 'questions', fn ($j) => $j->on('questions.user_id', '=', 'u.id')->on('questions.agent_id', '=', 'a.id'));
        $seats = $pairs->selectRaw('COUNT(*) allowed, COALESCE(SUM(questions.questions IS NULL),0) unused')->first();
        $fileTypes = $f->scope(DB::table('attachments')->where('generated', false)->where('created_at', '>=', BusinessDay::startUtc($f->from))->where('created_at', '<', BusinessDay::endUtc($f->to)))
            ->select('kind')->selectRaw('COUNT(*) files')->groupBy('kind')->orderByDesc('files')->get();
        $today = BusinessDay::today();
        $used = DB::table('usage_records')->where('purpose', 'chat')->where('work_date', $today)->select('user_id')->selectRaw('SUM(input_tokens+output_tokens) used, SUM(status="pending") pending')->groupBy('user_id');
        $granted = DB::table('token_grants')->where('work_date', $today)->select('user_id')->selectRaw('SUM(tokens) granted')->groupBy('user_id');
        $limits = ReportQueries::users($f)->whereIn('u.id', $people->pluck('id'))->leftJoinSub($used, 'used', 'used.user_id', '=', 'u.id')->leftJoinSub($granted, 'granted', 'granted.user_id', '=', 'u.id')
            ->select('u.id', 'u.name', 'u.daily_token_limit')->selectRaw('COALESCE(used.used,0) used, COALESCE(used.pending,0) pending, COALESCE(granted.granted,0) granted')->orderBy('u.name')->get()->map(fn ($r) => [
                'name' => $r->name, 'limit' => (int) ($r->daily_token_limit ?? Settings::get('default_daily_token_limit')) + (int) $r->granted, 'used' => (int) $r->used, 'pending' => (int) $r->pending])->all();

        return ['active' => $active, 'daily' => $daily, 'heat' => $heat, 'accounts' => (int) $counts->accounts, 'unused' => (int) $counts->unused, 'one_active_day' => (int) $counts->one_day, 'unused_people' => $unused,
            'matrix' => ['agents' => $agentList->items(), 'rows' => $matrix, 'page' => $people->currentPage(), 'last_page' => $people->lastPage(), 'total' => $people->total(), 'agent_page' => $agentList->currentPage(), 'agent_last_page' => $agentList->lastPage()],
            'seats' => ['allowed' => (int) $seats->allowed, 'unused' => (int) $seats->unused], 'files' => $fileTypes, 'words' => $this->words($f), 'limits' => $limits, 'limits_date' => $today,
            'notes' => ['Activity counts current user messages, not background analysis. Rolling windows end on the selected end date.', 'Access is current, not historical. Seat totals cover active employees and currently usable assistants.', 'Word frequency is a bounded keyword count, not AI topic classification; messages containing detected restricted data are skipped.', 'Allowances show current limits and today’s consumed/reserved tokens for the displayed people; they are not historical limit snapshots.']];
    }

    private function words(ReportFilters $f): array
    {
        $stop = array_fill_keys(explode(' ', 'the and for with this that from into your you are was were have has had can could would should will what when where which please thanks hello help need want make give write create draft show tell use using not but our about summary summarize'), true);
        $words = [];
        $n = 0;
        foreach (ReportQueries::messages($f, 'user')->select('m.id', 'm.content')->lazyByIdDesc(100, 'm.id', 'id')->take(5000) as $m) {
            $n++;
            if (SensitiveData::find($m->content)) {
                continue;
            }
            foreach (preg_split('/[^\p{L}\p{N}\x{2019}\x{0027}-]+/u', mb_strtolower($m->content)) ?: [] as $word) {
                if (mb_strlen($word) < 3 || mb_strlen($word) > 30 || isset($stop[$word]) || preg_match('/^\d+$/', $word)) {
                    continue;
                }
                if (count($words) < 10000 || isset($words[$word])) {
                    $words[$word] = ($words[$word] ?? 0) + 1;
                }
            }
        }
        arsort($words);

        return ['sampled_messages' => $n, 'sample_limit' => 5000, 'items' => collect(array_slice($words, 0, 36, true))->map(fn ($count, $word) => ['word' => $word, 'mentions' => $count])->values()->all()];
    }
}
