<?php

namespace App\Reports;

use App\Analysis\AnalysisContract;
use App\Analysis\ConversationAnalyzer;
use App\Models\AnalysisTopic;
use App\Models\ConversationAnalysis;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything the prompt-quality and task/topic screens show. It only reads results that are already stored: opening a
 * report never calls the AI. The agent produced the labels and the scores; every count, average, trend and
 * recommendation here is computed by code from those rows.
 */
final class AnalysisReports
{
    /** Fewest analyzed conversations before a recommendation is shown. Below it, one odd chat could decide the advice. */
    public const MIN_SAMPLE = 10;

    /** The employee's own page needs fewer, because it is only a hint to that person. */
    public const MIN_SAMPLE_OWN = 5;

    private const DROP = 0.3; // the average fell by at least this much since the previous period

    private const TOPIC_SHARE = 30; // one topic is at least this percent of the conversations

    private const LOW_COVERAGE = 50; // under this percent analyzed, read the charts with care

    public const LABELS = ['clarity' => 'Clarity', 'context' => 'Context', 'specifics' => 'Specifics', 'refinement' => 'Refinement', 'verification' => 'Verification'];

    /** Each conversation counts once, with its newest stored result of the current rubric. Deleted chats are kept. */
    private function latest(ReportFilters $f): mixed
    {
        $newest = DB::table('conversation_analyses')->selectRaw('MAX(id) as id')->where('status', ConversationAnalysis::VALID)
            ->where('rubric_version', AnalysisContract::RUBRIC_VERSION)->groupBy('conversation_id');
        $q = DB::table('conversation_analyses as a')->joinSub($newest, 'l', 'l.id', '=', 'a.id')->join('conversations as c', 'c.id', '=', 'a.conversation_id')
            ->where('c.last_message_at', '>=', BusinessDay::startUtc($f->from))->where('c.last_message_at', '<', BusinessDay::endUtc($f->to));

        return $f->scope($q, 'a.user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(ReportFilters $f): array
    {
        $rows = $this->latest($f)->get(['a.task_label', 'a.topic_ids', 'a.clarity', 'a.context', 'a.specifics', 'a.refinement', 'a.verification', 'a.average', DB::raw(ReportQueries::day('c.last_message_at').' as day')]);
        $n = $rows->count();
        $average = $n ? round($rows->avg('average'), 2) : null;
        $dimensions = $this->dimensions($rows);

        $previous = $this->previous($f);
        $coverage = $this->coverage($f);
        $topics = $this->topics($rows, $n);

        return [
            'state' => $this->state(),
            'rubric_version' => AnalysisContract::RUBRIC_VERSION,
            'totals' => ['conversations' => $n, 'average' => $average, 'previous_conversations' => $previous['conversations'], 'previous_average' => $previous['average']],
            'dimensions' => $dimensions,
            'daily' => $this->daily($f, $rows),
            'topics' => $topics,
            'tasks' => $this->tasks($rows),
            'coverage' => $coverage,
            'recommendations' => $this->recommendations($n, $average, $dimensions, $previous, $topics, $coverage, self::MIN_SAMPLE),
            'min_sample' => self::MIN_SAMPLE,
            'notes' => [
                'Scores describe how prompts were written in AI conversations. They are not a measure of anyone\'s overall work or performance.',
                'Each conversation counts once, using its newest result. A conversation with more than one topic counts under each topic, so topic counts add up to more than the total.',
                'Refinement is left out for conversations with a single employee message, because there is no answer to refine.',
            ],
        ];
    }

    /**
     * One person's own results for the last 90 days.
     *
     * @return array<string, mixed>
     */
    public function mine(User $user): array
    {
        $to = BusinessDay::today();
        $f = new ReportFilters(CarbonImmutable::parse($to)->subDays(89)->toDateString(), $to, $user->id, null);
        $data = $this->data($f);
        $data['recommendations'] = $this->recommendations($data['totals']['conversations'], $data['totals']['average'], $data['dimensions'], ['conversations' => $data['totals']['previous_conversations'], 'average' => $data['totals']['previous_average']],
            $data['topics'], $data['coverage'], self::MIN_SAMPLE_OWN, own: true);
        $data['min_sample'] = self::MIN_SAMPLE_OWN;
        $names = AnalysisTopic::pluck('name', 'id');
        $data['recent'] = $this->latest($f)->orderByDesc('c.last_message_at')->limit(20)
            ->get(['c.id as conversation_id', 'c.title', 'c.last_message_at', 'a.task_label', 'a.topic_ids', 'a.clarity', 'a.context', 'a.specifics', 'a.refinement', 'a.verification', 'a.average', 'a.tip'])
            ->map(fn ($r) => [
                'conversation_id' => $r->conversation_id, 'title' => $r->title, 'at' => CarbonImmutable::parse($r->last_message_at, 'UTC')->toIso8601String(), 'task_label' => $r->task_label,
                'topics' => collect(json_decode($r->topic_ids ?? '[]', true))->map(fn ($id) => $names[$id] ?? 'Removed topic')->values()->all(),
                'scores' => collect(AnalysisContract::DIMENSIONS)->mapWithKeys(fn ($d) => [$d => $r->{$d} === null ? null : (int) $r->{$d}])->all(),
                'average' => (float) $r->average, 'tip' => $r->tip,
            ])->all();
        $data['period'] = ['from' => $f->from, 'to' => $f->to];

        return $data;
    }

    /** Is analysis switched on and able to run? The screens use this to say "not enabled" instead of showing empty charts. */
    private function state(): array
    {
        $analyzer = app(ConversationAnalyzer::class);

        return ['enabled' => (bool) Settings::get('analysis_enabled'), 'agent_ready' => $analyzer->agent() !== null, 'topics_active' => AnalysisTopic::active()->count()];
    }

    /** @return list<array{key: string, label: string, average: float|null, samples: int}> */
    private function dimensions($rows): array
    {
        return collect(AnalysisContract::DIMENSIONS)->map(function ($d) use ($rows) {
            $values = $rows->pluck($d)->filter(fn ($v) => $v !== null);

            return ['key' => $d, 'label' => self::LABELS[$d], 'average' => $values->isEmpty() ? null : round($values->avg(), 2), 'samples' => $values->count()];
        })->all();
    }

    /** The same length of time just before the selected dates, for a fair comparison. */
    private function previous(ReportFilters $f): array
    {
        $days = CarbonImmutable::parse($f->from)->diffInDays(CarbonImmutable::parse($f->to)) + 1;
        $to = CarbonImmutable::parse($f->from)->subDay()->toDateString();
        $from = CarbonImmutable::parse($to)->subDays($days - 1)->toDateString();
        $rows = $this->latest(new ReportFilters($from, $to, $f->userId, $f->departmentId))->get(['a.average']);

        return ['conversations' => $rows->count(), 'average' => $rows->isEmpty() ? null : round($rows->avg('average'), 2)];
    }

    /** One row per Philippine date in the selection; a date with no results has no average (a gap, never zero). */
    private function daily(ReportFilters $f, $rows): array
    {
        $byDay = $rows->groupBy('day');
        $out = [];
        for ($d = CarbonImmutable::parse($f->from); $d->toDateString() <= $f->to; $d = $d->addDay()) {
            $day = $d->toDateString();
            $set = $byDay[$day] ?? collect();
            $out[] = ['day' => $day, 'conversations' => $set->count(), 'average' => $set->isEmpty() ? null : round($set->avg('average'), 2)];
        }

        return $out;
    }

    private function topics($rows, int $n): array
    {
        $names = AnalysisTopic::pluck('name', 'id');
        $count = [];
        foreach ($rows as $r) {
            foreach (json_decode($r->topic_ids ?? '[]', true) ?: [] as $id) {
                $count[$id] = ($count[$id] ?? 0) + 1;
            }
        }
        arsort($count);

        return collect($count)->map(fn ($c, $id) => ['id' => (int) $id, 'name' => $names[$id] ?? 'Removed topic', 'conversations' => $c, 'share' => $n ? round($c * 100 / $n, 1) : 0])->values()->all();
    }

    private function tasks($rows): array
    {
        return $rows->groupBy(fn ($r) => mb_strtolower($r->task_label))->map(fn ($g) => ['label' => $g->first()->task_label, 'conversations' => $g->count()])
            ->sortByDesc('conversations')->take(15)->values()->all();
    }

    /**
     * How much of the period's conversations have a stored result. Each conversation lands in exactly one state.
     *
     * @return array{eligible: int, analyzed: int, unassessable: int, stale: int, failed: int, pending: int, percent_analyzed: float|null}
     */
    private function coverage(ReportFilters $f): array
    {
        $rubric = AnalysisContract::RUBRIC_VERSION;
        $has = fn (string $status, string $extra = '') => "EXISTS (SELECT 1 FROM conversation_analyses x WHERE x.conversation_id = c.id AND x.rubric_version = '{$rubric}' AND x.status = '{$status}' {$extra})";
        $rows = $f->scope(DB::table('conversations as c'), 'c.user_id')->whereNull('c.deleted_at')
            ->where('c.last_message_at', '>=', BusinessDay::startUtc($f->from))->where('c.last_message_at', '<', BusinessDay::endUtc($f->to))
            ->whereExists(fn ($m) => $m->selectRaw('1')->from('messages as m')->whereColumn('m.conversation_id', 'c.id')->where('m.role', 'user')->whereNull('m.superseded_at')->where('m.content', '!=', ''))
            ->selectRaw($has('valid', 'AND x.analyzed_at >= c.last_message_at').' as current_valid, '
                .$has('unassessable', 'AND x.analyzed_at >= c.last_message_at').' as current_unassessable, '
                .$has('valid').' as any_valid, '
                .$has('failed', 'AND x.attempts >= '.ConversationAnalyzer::MAX_ATTEMPTS).' as gave_up')
            ->get();

        $out = ['eligible' => $rows->count(), 'analyzed' => 0, 'unassessable' => 0, 'stale' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($rows as $r) {
            $state = $r->current_valid ? 'analyzed' : ($r->current_unassessable ? 'unassessable' : ($r->any_valid ? 'stale' : ($r->gave_up ? 'failed' : 'pending')));
            $out[$state]++;
        }
        $out['percent_analyzed'] = $out['eligible'] ? round($out['analyzed'] * 100 / $out['eligible'], 1) : null;

        return $out;
    }

    /**
     * Plain rules over the numbers above. Every recommendation says what it is based on, and none appears until there
     * are enough analyzed conversations. Nothing here ranks or names a person.
     *
     * @return list<array{id: string, tone: string, title: string, detail: string, evidence: array<string, mixed>}>
     */
    private function recommendations(int $n, ?float $average, array $dimensions, array $previous, array $topics, array $coverage, int $min, bool $own = false): array
    {
        $who = $own ? 'your' : 'the selected group\'s';
        $out = [];
        if ($coverage['percent_analyzed'] !== null && $coverage['eligible'] >= $min && $coverage['percent_analyzed'] < self::LOW_COVERAGE) {
            $out[] = ['id' => 'low_coverage', 'tone' => 'warning', 'title' => 'Many conversations are not analyzed yet',
                'detail' => "Only {$coverage['percent_analyzed']}% of the {$coverage['eligible']} eligible conversations have a result, so read these numbers with care.",
                'evidence' => ['eligible' => $coverage['eligible'], 'analyzed' => $coverage['analyzed']]];
        }
        if ($n < $min) {
            return $out;
        }
        $scored = array_values(array_filter($dimensions, fn ($d) => $d['average'] !== null && $d['samples'] >= $min));
        if ($scored) {
            usort($scored, fn ($a, $b) => $a['average'] <=> $b['average']);
            $weak = $scored[0];
            $out[] = ['id' => 'weakest_area', 'tone' => 'info', 'title' => "{$weak['label']} is {$who} lowest-scoring area",
                'detail' => "It averages {$weak['average']} out of 5 over {$weak['samples']} conversations. Small improvements here would raise the overall score the most.",
                'evidence' => ['dimension' => $weak['key'], 'average' => $weak['average'], 'conversations' => $weak['samples']]];
        }
        if ($average !== null && $previous['average'] !== null && $previous['conversations'] >= $min && $previous['average'] - $average >= self::DROP) {
            $out[] = ['id' => 'decline', 'tone' => 'warning', 'title' => 'The overall score fell compared with the previous period',
                'detail' => "It went from {$previous['average']} to {$average} (out of 5), measured over {$previous['conversations']} and {$n} conversations.",
                'evidence' => ['previous_average' => $previous['average'], 'average' => $average, 'previous_conversations' => $previous['conversations'], 'conversations' => $n]];
        }
        if ($topics && $topics[0]['share'] >= self::TOPIC_SHARE) {
            $out[] = ['id' => 'top_topic', 'tone' => 'info', 'title' => "\"{$topics[0]['name']}\" is the most common topic",
                'detail' => "{$topics[0]['share']}% of the analyzed conversations ({$topics[0]['conversations']} of {$n}) are about it.",
                'evidence' => ['topic_id' => $topics[0]['id'], 'conversations' => $topics[0]['conversations'], 'share' => $topics[0]['share']]];
        }

        return $out;
    }
}
