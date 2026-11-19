<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Typed access to system settings. Reads are open to server code; writes are Super Admin only,
 * validated against the registry in config/thryve.php, and audited (secrets redacted).
 * Settings marked `secret` are encrypted at rest, never returned to a browser and never written to the audit log.
 */
final class Settings
{
    public static function definitions(): array
    {
        return config('thryve.settings');
    }

    private static function isSecret(array $def): bool
    {
        return (bool) ($def['secret'] ?? false);
    }

    private static function read(array $def, mixed $stored): mixed
    {
        if (! self::isSecret($def)) {
            return $stored;
        }
        try {
            return $stored === null ? null : Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null; // e.g. the app key changed: treat as not set rather than failing
        }
    }

    public static function get(string $key): mixed
    {
        $def = self::definitions()[$key] ?? throw new \InvalidArgumentException("Unknown setting [{$key}].");
        $row = Setting::where('key', $key)->first();

        return $row ? self::read($def, $row->value) : $def['default'];
    }

    /** @return array<string, array{value: mixed, default: mixed, label: string, secret: bool, group: string, is_set: bool}> */
    public static function all(): array
    {
        $stored = Setting::pluck('value', 'key');
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $secret = self::isSecret($def);
            $out[$key] = [
                'value' => $secret ? null : ($stored->has($key) ? $stored[$key] : $def['default']),
                'default' => $secret ? null : $def['default'],
                'label' => $def['label'] ?? $key,
                'secret' => $secret,
                'group' => $def['group'] ?? 'general',
                'is_set' => $stored->has($key),
            ];
        }

        return $out;
    }

    private static function cast(array $def, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($def['type'] ?? null) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            default => $value,
        };
    }

    /**
     * Validate and save several settings at once: all or nothing.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, true> the keys that actually changed
     */
    public static function update(array $values, User $actor): array
    {
        if (! $actor->hasRole(Role::SUPER_ADMIN)) {
            throw new AuthorizationException;
        }

        $defs = self::definitions();
        $unknown = array_diff(array_keys($values), array_keys($defs));
        if ($unknown) {
            throw ValidationException::withMessages(collect($unknown)->mapWithKeys(fn ($k) => [$k => ['Unknown setting.']])->all());
        }
        // A setting is required unless its rules say it may be empty.
        $rules = collect($defs)->only(array_keys($values))
            ->map(fn ($d) => in_array('nullable', $d['rules'], true) ? $d['rules'] : ['required', ...$d['rules']])->all();
        $validated = Validator::make($values, $rules)->validate();

        $changes = [];
        $protectedChanged = [];
        DB::transaction(function () use ($validated, $defs, $actor, &$changes, &$protectedChanged) {
            foreach ($validated as $key => $raw) {
                $def = $defs[$key];
                $value = self::cast($def, $raw);
                $current = self::get($key);
                if ($current === $value) {
                    continue;
                }
                if (self::isSecret($def)) {
                    if ($value === null || $value === '') {
                        Setting::where('key', $key)->delete();
                    } else {
                        Setting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString((string) $value), 'updated_by' => $actor->id]);
                    }
                    $protectedChanged[] = $key; // only the fact that it changed is recorded, never a value
                } else {
                    Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actor->id]);
                    $changes[$key] = ['from' => $current, 'to' => $value];
                }
            }
        });

        if ($changes || $protectedChanged) {
            Audit::log('settings.updated', $actor, null, array_filter(['changes' => $changes, 'protected_changed' => $protectedChanged]));
        }

        return array_fill_keys(array_merge(array_keys($changes), $protectedChanged), true);
    }
}
