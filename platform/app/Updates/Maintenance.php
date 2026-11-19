<?php

namespace App\Updates;

/**
 * Maintenance mode for updates. While it is on, everyone except the Super Admin running the update (who holds a
 * bypass cookie) sees a maintenance notice. The flag is a file so it works even if the database is being restored.
 */
class Maintenance
{
    public const COOKIE = 'thryve_maint';

    private function file(): string
    {
        return storage_path('framework/thryve-maintenance.json');
    }

    public function active(): bool
    {
        return is_file($this->file());
    }

    public function data(): ?array
    {
        return $this->active() ? json_decode((string) file_get_contents($this->file()), true) : null;
    }

    public function on(string $message): string
    {
        $secret = bin2hex(random_bytes(16));
        if (! is_dir(dirname($this->file()))) {
            mkdir(dirname($this->file()), 0775, true);
        }
        file_put_contents($this->file(), json_encode(['since' => now()->toIso8601String(), 'message' => $message, 'secret' => $secret]), LOCK_EX);

        return $this->token($secret);
    }

    public function off(): void
    {
        @unlink($this->file());
    }

    /** The value the Super Admin's browser carries to keep working during maintenance. */
    public function bypassToken(): ?string
    {
        $d = $this->data();

        return $d ? $this->token($d['secret']) : null;
    }

    public function allows(?string $cookieValue): bool
    {
        $token = $this->bypassToken();

        return $token !== null && is_string($cookieValue) && hash_equals($token, $cookieValue);
    }

    private function token(string $secret): string
    {
        return hash_hmac('sha256', 'thryve-maintenance-bypass', $secret);
    }
}
