<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\PolicyAcknowledgment;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class PolicyAcknowledgmentTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function freshAdminCookie(): array
    {
        $user = $this->makeUser(Role::ADMIN, acknowledged: false);
        [, $c] = $this->signIn($user);

        return [$user, $c];
    }

    public function test_me_reports_whether_the_current_policy_is_acknowledged(): void
    {
        [$user, $c] = $this->freshAdminCookie();

        $this->device($c)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.policy_acknowledged', false);
        $this->device($c)->postJson('/api/policy/acknowledge', ['accept' => true])->assertOk();
        $this->device($c)->getJson('/api/auth/me')->assertJsonPath('data.policy_acknowledged', true);
        $this->assertSame(1, PolicyAcknowledgment::where('user_id', $user->id)->count());
    }

    public function test_policy_text_is_available_before_acknowledging(): void
    {
        [, $c] = $this->freshAdminCookie();

        $r = $this->device($c)->getJson('/api/policy')->assertOk();
        $this->assertSame(config('thryve.policy.version'), $r->json('data.version'));
        $this->assertNotEmpty($r->json('data.body'));
        $this->assertFalse($r->json('data.acknowledged'));
    }

    public function test_feature_routes_stay_closed_until_acknowledged(): void
    {
        [, $c] = $this->freshAdminCookie();

        $this->device($c)->getJson('/api/users')->assertStatus(403)->assertJsonPath('error.code', 'policy_not_acknowledged');
        $this->device($c)->getJson('/api/departments')->assertStatus(403)->assertJsonPath('error.code', 'policy_not_acknowledged');

        $this->device($c)->postJson('/api/policy/acknowledge', ['accept' => true])->assertOk();
        $this->device($c)->getJson('/api/users')->assertOk();
    }

    public function test_sign_out_and_own_password_stay_available_before_acknowledging(): void
    {
        [, $c] = $this->freshAdminCookie();
        $new = 'a-completely-new-password';

        $this->device($c)->postJson('/api/account/password', ['current_password' => self::PW, 'password' => $new, 'password_confirmation' => $new])->assertOk();
        $this->device($c)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_acknowledgment_requires_explicit_acceptance_and_is_audited_once(): void
    {
        [$user, $c] = $this->freshAdminCookie();

        $this->device($c)->postJson('/api/policy/acknowledge', [])->assertStatus(422);
        $this->device($c)->postJson('/api/policy/acknowledge', ['accept' => false])->assertStatus(422);
        $this->assertSame(0, PolicyAcknowledgment::count());

        $this->device($c)->postJson('/api/policy/acknowledge', ['accept' => true])->assertOk();
        $this->device($c)->postJson('/api/policy/acknowledge', ['accept' => true])->assertOk(); // repeat is harmless
        $this->assertSame(1, PolicyAcknowledgment::count());
        $this->assertSame(1, AuditEntry::where('action', 'policy.acknowledged')->where('actor_id', $user->id)->count());
    }

    public function test_a_new_policy_version_must_be_acknowledged_again(): void
    {
        [$user, $c] = $this->freshAdminCookie();
        $this->device($c)->postJson('/api/policy/acknowledge', ['accept' => true])->assertOk();
        $this->device($c)->getJson('/api/users')->assertOk();

        config(['thryve.policy.version' => '2']);

        $this->device($c)->getJson('/api/users')->assertStatus(403)->assertJsonPath('error.code', 'policy_not_acknowledged');
        $this->device($c)->postJson('/api/policy/acknowledge', ['accept' => true])->assertOk();
        $this->device($c)->getJson('/api/users')->assertOk();
        $this->assertSame(['1', '2'], PolicyAcknowledgment::where('user_id', $user->id)->orderBy('policy_version')->pluck('policy_version')->all());
    }

    public function test_policy_endpoints_need_a_session(): void
    {
        $this->device(null)->getJson('/api/policy')->assertStatus(401);
        $this->device(null)->postJson('/api/policy/acknowledge', ['accept' => true])->assertStatus(401);
    }
}
