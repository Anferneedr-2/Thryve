<?php

namespace App\Models;

use App\Analysis\ConversationAnalyzer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The stored result of analyzing one conversation at one message version. See App\Analysis\AnalysisContract. */
class ConversationAnalysis extends Model
{
    public const VALID = 'valid';

    public const FAILED = 'failed';

    public const UNASSESSABLE = 'unassessable';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['topic_ids' => 'array', 'analyzed_at' => 'datetime', 'next_retry_at' => 'datetime', 'average' => 'float'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeValid($q)
    {
        return $q->where('status', self::VALID);
    }

    /**
     * Make failed analyses that still have attempts left due now: used when the report agent has been fixed and passed
     * a connection test, so the work does not wait out a timer that was set while it was broken.
     *
     * @return int how many were made due
     */
    public static function retryFailedNow(?int $agentId = null): int
    {
        return static::where('status', self::FAILED)->where('attempts', '<', ConversationAnalyzer::MAX_ATTEMPTS)
            ->when($agentId, fn ($q) => $q->where('agent_id', $agentId))->update(['next_retry_at' => now()]);
    }
}
