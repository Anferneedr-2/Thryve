<?php

namespace App\Support;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Central audit writer. Metadata must never contain passwords, tokens or secrets;
 * keys that look sensitive are dropped as a safety net.
 */
final class Audit
{
    private const FORBIDDEN = ['password', 'secret', 'token', 'key', 'authorization'];

    public static function log(string $action, ?User $actor = null, ?Model $target = null, array $metadata = [], ?string $ip = null): AuditEntry
    {
        return AuditEntry::create([
            'actor_id' => $actor?->id,
            'actor_username' => $actor?->username,
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'metadata' => self::clean($metadata) ?: null,
            'ip_address' => $ip ?? request()?->ip(),
        ]);
    }

    private static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match('/'.implode('|', self::FORBIDDEN).'/i', $k)) {
                continue;
            }
            $out[$k] = is_array($v) ? self::clean($v) : $v;
        }

        return $out;
    }
}
