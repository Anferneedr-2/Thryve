<?php

namespace App\Ai;

use App\Models\TokenGrant;
use App\Models\UsageRecord;
use App\Models\User;
use App\Notifications\Notifier;
use App\Support\BusinessDay;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily token allowance per person (never per department, never a message count), counted in Philippine business days.
 * Everything a person causes counts, including failed, edited and regenerated attempts. Requests still in flight count
 * with their estimate so two quick requests cannot both slip under the limit.
 * Background analysis uses a separate system budget and is never charged to anyone's allowance.
 */
class Allowance
{
    public const THRESHOLDS = [80, 90, 100];

    public function limit(User $user): int
    {
        return (int) ($user->daily_token_limit ?? Settings::get('default_daily_token_limit'));
    }

    public function granted(User $user, string $date): int
    {
        return (int) TokenGrant::where('user_id', $user->id)->where('work_date', $date)->sum('tokens');
    }

    public function used(User $user, string $date): int
    {
        return $this->sum(UsageRecord::where('user_id', $user->id)->where('purpose', 'chat')->where('work_date', $date));
    }

    public function usesEstimates(User $user, string $date): bool
    {
        return UsageRecord::where('user_id', $user->id)->where('purpose', 'chat')->where('work_date', $date)->where('is_estimate', true)->exists();
    }

    private function sum($query): int
    {
        return (int) $query->sum(DB::raw('input_tokens + output_tokens'));
    }

    /** @return array{work_date: string, limit: int, extra: int, total: int, used: int, remaining: int, percent: int, includes_estimates: bool, resets_at: string} */
    public function summary(User $user, ?string $date = null): array
    {
        $date ??= BusinessDay::today();
        $limit = $this->limit($user);
        $extra = $this->granted($user, $date);
        $total = $limit + $extra;
        $used = $this->used($user, $date);

        return [
            'work_date' => $date, 'limit' => $limit, 'extra' => $extra, 'total' => $total, 'used' => $used,
            'remaining' => max(0, $total - $used),
            'percent' => $total > 0 ? min(100, (int) floor($used * 100 / $total)) : 100,
            'includes_estimates' => $this->usesEstimates($user, $date),
            'resets_at' => CarbonImmutable::instance(BusinessDay::endUtc($date))->toIso8601String(),
        ];
    }

    public function systemBudget(): ?int
    {
        $b = Settings::get('system_daily_token_budget');

        return $b === null ? null : (int) $b;
    }

    public function systemUsed(string $date): int
    {
        return $this->sum(UsageRecord::where('purpose', 'analysis')->where('work_date', $date));
    }

    /** Hard check before any request. @throws TokenLimitReached */
    public function assertAvailable(?User $user, string $purpose, ?string $date = null): void
    {
        $date ??= BusinessDay::today();
        if ($purpose === 'analysis') {
            $budget = $this->systemBudget();
            if ($budget !== null && $this->systemUsed($date) >= $budget) {
                throw new TokenLimitReached('system');
            }

            return;
        }
        if ($this->summary($user, $date)['remaining'] <= 0) {
            throw new TokenLimitReached('user');
        }
    }

    /** Tell the person once per day when they pass 80, 90 and 100 percent. */
    public function notifyThresholds(User $user, string $date): void
    {
        $s = $this->summary($user, $date);
        foreach (self::THRESHOLDS as $t) {
            if ($s['percent'] < $t) {
                continue;
            }
            $title = $t >= 100 ? "You have used all of today's tokens" : "You have used {$t}% of today's tokens";
            $body = $t >= 100 ? 'Your allowance resets at midnight (Philippine time). Ask an administrator if you need more today.' : 'Your allowance resets at midnight (Philippine time).';
            app(Notifier::class)->notify('token.threshold', $title, $body, ['percent' => $t, 'date' => $date], "tokens:{$user->id}:{$date}:{$t}", $user);
        }
    }

    /** Serialise check-and-reserve per person (or for the system budget) across simultaneous requests. */
    public function withLock(string $key, callable $fn): mixed
    {
        $name = 'thryve:allow:'.$key;
        $got = (int) DB::selectOne('select GET_LOCK(?, 5) as l', [$name])->l;
        if ($got !== 1) {
            throw new \RuntimeException('Could not reserve tokens right now. Please try again.');
        }
        try {
            return $fn();
        } finally {
            DB::select('select RELEASE_LOCK(?)', [$name]);
        }
    }
}
