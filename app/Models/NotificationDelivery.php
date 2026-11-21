<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
