<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only. Entries can be created by the central audit writer (App\Support\Audit) and are otherwise
 * immutable: updates and deletes throw unless run inside AuditEntry::withMutation(), which only the
 * Super Admin audit tools (P08.05, policy D11) use.
 */
#[Fillable(['actor_id', 'actor_username', 'action', 'target_type', 'target_id', 'metadata', 'ip_address'])]
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    private static bool $mutable = false;

    protected static function booted(): void
    {
        static::updating(fn () => self::$mutable ?: throw new LogicException('Audit entries are append-only.'));
        static::deleting(fn () => self::$mutable ?: throw new LogicException('Audit entries are append-only.'));
    }

    /** Run a callback that is allowed to edit or delete audit entries. */
    public static function withMutation(callable $callback): mixed
    {
        self::$mutable = true;
        try {
            return $callback();
        } finally {
            self::$mutable = false;
        }
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
