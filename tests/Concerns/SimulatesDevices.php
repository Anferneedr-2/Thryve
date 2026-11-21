<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;

/**
 * Lets one test drive several browsers: each "device" only sends its own session cookie, and every request
 * starts from a clean app state like a fresh PHP process. Sessions are stored in the database, as in production.
 */
trait SimulatesDevices
{
    protected const PW = 'correct-horse-battery';

    protected function useDatabaseSessions(): void
    {
        config(['session.driver' => 'database']);
    }

    protected function makeUser(string $role = Role::EMPLOYEE, array $attrs = [], bool $acknowledged = true): User
    {
        $user = User::factory()->role($role)->create($attrs + ['password' => self::PW]);
        // Most tests are not about the policy gate, so users start out having acknowledged it.
        if ($acknowledged) {
            \App\Models\PolicyAcknowledgment::create(['user_id' => $user->id, 'policy_version' => config('thryve.policy.version'), 'acknowledged_at' => now()]);
        }

        return $user;
    }

    protected function device(?string $cookie): static
    {
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('auth')->forgetGuards();
        $this->withCredentials(); // JSON requests only carry cookies when this is on
        // The test client encrypts default cookies the same way the app does, so the real decryption runs.
        $this->unencryptedCookies = [];
        $this->defaultCookies = $cookie ? [config('session.cookie') => $cookie] : [];

        return $this;
    }

    protected function cookieOf($response): ?string
    {
        return $response->getCookie(config('session.cookie'))?->getValue();
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: ?string} response and the device's session cookie */
    protected function signIn(User $user, ?string $existingCookie = null, string $password = self::PW): array
    {
        $r = $this->device($existingCookie)->postJson('/api/auth/login', ['username' => $user->username, 'password' => $password]);

        return [$r, $this->cookieOf($r) ?? $existingCookie];
    }

    /** Sign a fresh user of this role in and return that device's cookie. */
    protected function cookieFor(string $role, array $attrs = []): string
    {
        [, $cookie] = $this->signIn($this->makeUser($role, $attrs));

        return $cookie;
    }
}
