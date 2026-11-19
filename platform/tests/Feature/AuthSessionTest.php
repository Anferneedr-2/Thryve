<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions(); // the in-memory driver would also leak state between simulated devices
    }

    public function test_login_returns_the_user_without_secrets_and_records_audit(): void
    {
        $user = $this->makeUser();
        [$r] = $this->signIn($user);

        $r->assertOk()->assertJsonPath('data.username', $user->username)->assertJsonPath('data.role', 'employee');
        $this->assertStringNotContainsString(self::PW, $r->getContent());
        $this->assertStringNotContainsString('session_id', $r->getContent());
        $this->assertStringNotContainsString('"password"', $r->getContent());
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertSame(1, AuditEntry::where('action', 'auth.login')->where('actor_id', $user->id)->count());
    }

    public function test_me_requires_a_session_and_returns_the_current_user(): void
    {
        $this->device(null)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

        $user = $this->makeUser();
        [, $cookie] = $this->signIn($user);
        $this->device($cookie)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_unauthenticated_api_calls_get_the_401_envelope_even_without_an_accept_header(): void
    {
        $this->device(null)->get('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->device(null)->post('/api/auth/logout')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_session_id_changes_on_login(): void
    {
        $user = $this->makeUser();
        $guestResponse = $this->device(null)->getJson('/api/auth/me'); // anonymous session
        $guestId = $this->cookieOf($guestResponse);
        [, $after] = $this->signIn($user, $guestId);

        $this->assertNotNull($after);
        $this->assertNotSame($guestId, $user->fresh()->session_id, 'login must issue a fresh session id');
    }

    public function test_wrong_password_and_unknown_user_get_the_same_generic_error(): void
    {
        $user = $this->makeUser();

        $bad = $this->device(null)->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'nope-nope-nope']);
        $none = $this->device(null)->postJson('/api/auth/login', ['username' => 'nobody-here', 'password' => 'nope-nope-nope']);

        $bad->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertSame($bad->json('error'), $none->json('error'));
    }

    public function test_failed_logins_are_audited_with_a_reason_and_never_the_password(): void
    {
        $user = $this->makeUser();
        $this->device(null)->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'wrong-secret-pw']);
        $this->device(null)->postJson('/api/auth/login', ['username' => 'ghost', 'password' => 'wrong-secret-pw']);

        $entries = AuditEntry::where('action', 'auth.login_failed')->orderBy('id')->get();
        $this->assertSame(['bad_password', 'unknown_user'], $entries->pluck('metadata.reason')->all());
        $this->assertSame('ghost', $entries[1]->metadata['username']);
        $this->assertSame($user->id, $entries[0]->actor_id);
        $this->assertStringNotContainsString('wrong-secret-pw', json_encode($entries->toArray()));
    }

    public function test_there_is_no_account_lockout(): void
    {
        $user = $this->makeUser();
        for ($i = 0; $i < 12; $i++) {
            $this->device(null)->postJson('/api/auth/login', ['username' => $user->username, 'password' => "wrong-pass-$i"])->assertStatus(401);
        }
        [$r] = $this->signIn($user);
        $r->assertOk();
    }

    public function test_deactivated_account_cannot_sign_in_and_looks_like_any_failure(): void
    {
        $user = $this->makeUser(attrs: ['is_active' => false, 'deactivated_at' => now()]);
        [$r] = $this->signIn($user);

        $r->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
        $this->assertSame('inactive', AuditEntry::where('action', 'auth.login_failed')->first()->metadata['reason']);
        $this->assertNull($user->fresh()->session_id);
    }

    public function test_deactivating_a_signed_in_user_ends_access_on_the_next_request(): void
    {
        $user = $this->makeUser();
        [, $cookie] = $this->signIn($user);
        $this->device($cookie)->getJson('/api/auth/me')->assertOk();

        $user->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        $this->device($cookie)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'account_deactivated');
        $this->device($cookie)->getJson('/api/auth/me')->assertStatus(401); // and stays out
    }

    public function test_second_device_invalidates_the_first(): void
    {
        $user = $this->makeUser();
        [, $deviceA] = $this->signIn($user);
        $this->device($deviceA)->getJson('/api/auth/me')->assertOk();

        [, $deviceB] = $this->signIn($user);
        $this->assertNotSame($deviceA, $deviceB);

        $this->device($deviceB)->getJson('/api/auth/me')->assertOk();
        $this->device($deviceA)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'session_replaced');

        // A signs in again: now B is the one that loses access.
        [, $deviceA2] = $this->signIn($user);
        $this->device($deviceA2)->getJson('/api/auth/me')->assertOk();
        $this->device($deviceB)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'session_replaced');
    }

    public function test_other_users_sessions_are_unaffected(): void
    {
        [$u1, $u2] = [$this->makeUser(), $this->makeUser()];
        [, $c1] = $this->signIn($u1);
        [, $c2] = $this->signIn($u2);

        $this->device($c1)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $u1->id);
        $this->device($c2)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $u2->id);
    }

    public function test_logout_ends_the_session_and_is_audited(): void
    {
        $user = $this->makeUser();
        [, $cookie] = $this->signIn($user);

        $this->device($cookie)->postJson('/api/auth/logout')->assertOk();
        $this->assertNull($user->fresh()->session_id);
        $this->device($cookie)->getJson('/api/auth/me')->assertStatus(401);
        $this->assertSame(1, AuditEntry::where('action', 'auth.logout')->where('actor_id', $user->id)->count());
    }

    public function test_only_super_admin_can_terminate_sessions(): void
    {
        $target = $this->makeUser();
        [, $targetCookie] = $this->signIn($target);

        $this->device(null)->postJson("/api/users/{$target->id}/terminate-session")->assertStatus(401);

        foreach ([Role::EMPLOYEE, Role::ADMIN] as $role) {
            $actor = $this->makeUser($role);
            [, $c] = $this->signIn($actor);
            $this->device($c)->postJson("/api/users/{$target->id}/terminate-session")->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        }
        $this->device($targetCookie)->getJson('/api/auth/me')->assertOk(); // still signed in

        $super = $this->makeUser(Role::SUPER_ADMIN);
        [, $sc] = $this->signIn($super);
        $this->device($sc)->postJson("/api/users/{$target->id}/terminate-session")->assertOk();

        $this->device($targetCookie)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'session_terminated');
        $this->assertSame(1, AuditEntry::where('action', 'auth.session_terminated')->where('actor_id', $super->id)->where('target_id', $target->id)->count());

        $this->device($sc)->postJson('/api/users/999999/terminate-session')->assertStatus(404);
    }

    public function test_a_terminated_user_can_sign_in_again(): void
    {
        $target = $this->makeUser();
        $super = $this->makeUser(Role::SUPER_ADMIN);
        $this->signIn($target);
        [, $sc] = $this->signIn($super);
        $this->device($sc)->postJson("/api/users/{$target->id}/terminate-session")->assertOk();

        [$r, $c] = $this->signIn($target);
        $r->assertOk();
        $this->device($c)->getJson('/api/auth/me')->assertOk();
    }

    public function test_login_input_is_validated(): void
    {
        $this->device(null)->postJson('/api/auth/login', [])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_database_sessions_only_one_can_act_and_the_stale_one_is_cleaned_up_on_contact(): void
    {
        $user = $this->makeUser();

        [, $a] = $this->signIn($user);
        $idA = $user->fresh()->session_id;
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());

        [, $b] = $this->signIn($user);
        $idB = $user->fresh()->session_id;
        $this->assertNotSame($idA, $idB);
        $this->assertSame($idB, $user->fresh()->session_id, 'only the newest session id is current');

        $this->device($a)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'session_replaced');
        $this->assertSame(0, DB::table('sessions')->where('id', $idA)->count(), 'the stale session is removed once it calls in');
        $this->device($b)->getJson('/api/auth/me')->assertOk();
        $this->assertOnlySessionWithLogin($idB);
    }

    public function test_terminated_session_is_rejected_and_removed_on_next_contact(): void
    {
        $target = $this->makeUser();
        $super = $this->makeUser(Role::SUPER_ADMIN);
        [, $tc] = $this->signIn($target);
        [, $sc] = $this->signIn($super);
        $targetSession = $target->fresh()->session_id;

        $this->device($sc)->postJson("/api/users/{$target->id}/terminate-session")->assertOk();
        $this->assertNull($target->fresh()->session_id);

        $this->device($tc)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'session_terminated');
        $this->assertSame(0, DB::table('sessions')->where('id', $targetSession)->count(), 'the terminated session row is removed');
        $this->assertSame(1, DB::table('sessions')->where('id', $super->fresh()->session_id)->count(), 'the admin session is untouched');
    }

    /** Logging a stale session out leaves only an empty guest row behind: no other row may still hold a login. */
    private function assertOnlySessionWithLogin(string $sessionId): void
    {
        foreach (DB::table('sessions')->get() as $row) {
            $hasLogin = str_contains(base64_decode($row->payload), 'login_web_');
            $this->assertSame($row->id === $sessionId, $hasLogin, "session {$row->id} login state");
        }
    }

    public function test_prune_command_removes_expired_sessions_and_stale_ids_only(): void
    {
        config(['session.driver' => 'database', 'session.lifetime' => 60]);
        $live = $this->makeUser();
        $stale = $this->makeUser(attrs: ['session_id' => 'gone-session-id']);
        $this->signIn($live);
        DB::table('sessions')->insert(['id' => 'old-row', 'user_id' => null, 'payload' => 'x', 'last_activity' => now()->subHours(3)->getTimestamp()]);

        $this->artisan('thryve:prune-sessions')->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->where('id', 'old-row')->count());
        $this->assertNull($stale->fresh()->session_id);
        $this->assertNotNull($live->fresh()->session_id);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $live->id)->count());
    }

    public function test_prune_is_scheduled_and_inert_for_non_database_drivers(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->pluck('command')->implode(' ');
        $this->assertStringContainsString('thryve:prune-sessions', $events);
        $this->artisan('thryve:prune-sessions')->assertSuccessful();
    }

    public function test_session_cookie_is_http_only_and_ends_with_the_browser(): void
    {
        $user = $this->makeUser();
        [$r] = $this->signIn($user);
        $cookie = $r->getCookie(config('session.cookie'), false);

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(0, $cookie->getExpiresTime(), 'session cookie, not persistent');
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_audit_helper_drops_secret_looking_metadata(): void
    {
        $e = \App\Support\Audit::log('x.test', null, null, ['reason' => 'ok', 'password' => 'p', 'nested' => ['api_key' => 'k', 'keep' => 1]]);
        $this->assertSame(['reason' => 'ok', 'nested' => ['keep' => 1]], $e->fresh()->metadata);
    }
}
