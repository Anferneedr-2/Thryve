<?php

namespace Tests\Feature;

use App\Models\PolicyAcknowledgment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** An account whose password an administrator chose must get its own password before anything else opens. */
class PasswordChangeRequiredTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    private const START = 'start-password-123';

    private const OWN = 'my-own-password-456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(RoleSeeder::class);
    }

    private function newAccount(string $role = Role::EMPLOYEE): User
    {
        $id = $this->device($this->cookieFor(Role::SUPER_ADMIN))->postJson('/api/users', [
            'username' => 'new.person', 'name' => 'New Person', 'password' => self::START, 'role' => $role,
        ])->assertCreated()->json('data.id');

        return User::findOrFail($id);
    }

    private function change(string $cookie, string $current, string $new)
    {
        return $this->device($cookie)->postJson('/api/account/password', ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new]);
    }

    public function test_a_new_account_must_choose_its_own_password_and_sign_in_says_so(): void
    {
        $user = $this->newAccount();
        $this->assertTrue($user->must_change_password);

        [$login] = $this->signIn($user, password: self::START);

        $login->assertOk()->assertJsonPath('data.must_change_password', true);
    }

    public function test_an_administrator_reset_requires_a_new_password_but_resetting_your_own_does_not(): void
    {
        $employee = $this->makeUser(Role::EMPLOYEE);
        $super = $this->makeUser(Role::SUPER_ADMIN);
        [, $cookie] = $this->signIn($super);

        $this->device($cookie)->postJson("/api/users/{$employee->id}/reset-password", ['password' => self::START])->assertOk();
        $this->assertTrue($employee->fresh()->must_change_password);

        $this->device($cookie)->postJson("/api/users/{$super->id}/reset-password", ['password' => self::OWN.'-super'])->assertOk();
        $this->assertFalse($super->fresh()->must_change_password);
    }

    public function test_everything_but_the_change_stays_closed_until_the_password_is_changed(): void
    {
        $user = $this->newAccount();
        [, $cookie] = $this->signIn($user, password: self::START);

        foreach (['/api/conversations', '/api/chat/agents', '/api/usage/today', '/api/analysis/mine', '/api/attendance/me'] as $url) {
            $this->device($cookie)->getJson($url)->assertStatus(403)->assertJsonPath('error.code', 'password_change_required');
        }
        $this->device($cookie)->postJson('/api/chat', ['message' => 'hi'])->assertStatus(403)->assertJsonPath('error.code', 'password_change_required');
        $this->device($cookie)->postJson('/api/attendance/heartbeat')->assertStatus(403)->assertJsonPath('error.code', 'password_change_required');

        $this->device($cookie)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->device($cookie)->getJson('/api/policy')->assertOk();
    }

    public function test_choosing_a_new_password_clears_the_requirement_and_opens_the_app(): void
    {
        $user = $this->newAccount();
        PolicyAcknowledgment::create(['user_id' => $user->id, 'policy_version' => config('thryve.policy.version'), 'acknowledged_at' => now()]);
        [, $cookie] = $this->signIn($user, password: self::START);

        $this->change($cookie, 'not-the-right-one', self::OWN)->assertStatus(422);
        $this->change($cookie, self::START, self::START)->assertStatus(422); // the given password cannot be kept
        $this->assertTrue($user->fresh()->must_change_password);

        $this->change($cookie, self::START, self::OWN)->assertOk();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::OWN, $user->fresh()->password));
        $this->device($cookie)->getJson('/api/conversations')->assertOk();
        $this->device($cookie)->getJson('/api/auth/me')->assertJsonPath('data.must_change_password', false);
    }

    public function test_the_password_comes_before_the_policy(): void
    {
        $user = $this->newAccount(); // has not accepted the policy either
        [, $cookie] = $this->signIn($user, password: self::START);

        $this->device($cookie)->getJson('/api/conversations')->assertJsonPath('error.code', 'password_change_required');

        $this->change($cookie, self::START, self::OWN)->assertOk();

        $this->device($cookie)->getJson('/api/conversations')->assertStatus(403)->assertJsonPath('error.code', 'policy_not_acknowledged');
    }

    public function test_an_existing_account_is_not_made_to_change_anything(): void
    {
        [, $cookie] = $this->signIn($this->makeUser(Role::EMPLOYEE));

        $this->device($cookie)->getJson('/api/auth/me')->assertJsonPath('data.must_change_password', false);
        $this->device($cookie)->getJson('/api/conversations')->assertOk();
    }
}
