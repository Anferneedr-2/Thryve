<?php

namespace App\Models;

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
}
