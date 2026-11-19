<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class); // the installer seeds the three roles
    }

    private function newUserPayload(array $o = []): array
    {
        return $o + ['username' => 'new.person', 'name' => 'New Person', 'email' => 'new@example.com', 'password' => 'a-long-test-password', 'role' => 'employee'];
    }

    // ---- access matrix -------------------------------------------------------------------------

    public function test_unauthenticated_requests_are_rejected_everywhere(): void
    {
        $u = $this->makeUser();
        foreach ([['get', '/api/users'], ['post', '/api/users'], ['get', "/api/users/{$u->id}"], ['patch', "/api/users/{$u->id}"],
            ['post', "/api/users/{$u->id}/deactivate"], ['post', "/api/users/{$u->id}/reactivate"], ['post', "/api/users/{$u->id}/reset-password"],
            ['get', '/api/departments'], ['post', '/api/departments'], ['post', '/api/account/password']] as [$m, $url]) {
            $this->device(null)->json($m, $url)->assertStatus(401);
        }
    }

    public function test_employees_cannot_use_any_management_endpoint(): void
    {
        $target = $this->makeUser();
        $dept = Department::create(['name' => 'Ops']);
        $c = $this->cookieFor(Role::EMPLOYEE);

        foreach ([['get', '/api/users'], ['post', '/api/users'], ['get', "/api/users/{$target->id}"], ['patch', "/api/users/{$target->id}"],
            ['post', "/api/users/{$target->id}/deactivate"], ['post', "/api/users/{$target->id}/reactivate"], ['post', "/api/users/{$target->id}/reset-password"],
            ['get', '/api/departments'], ['post', '/api/departments'], ['patch', "/api/departments/{$dept->id}"], ['delete', "/api/departments/{$dept->id}"],
            ['post', "/api/users/{$target->id}/terminate-session"]] as [$m, $url]) {
            $this->device($c)->json($m, $url, [])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        }
    }

    // ---- admin boundary ------------------------------------------------------------------------

    public function test_admin_can_create_and_manage_employees_but_not_admins(): void
    {
        $c = $this->cookieFor(Role::ADMIN);

        $this->device($c)->postJson('/api/users', $this->newUserPayload())->assertCreated()->assertJsonPath('data.role', 'employee');
        $this->device($c)->postJson('/api/users', $this->newUserPayload(['username' => 'x.admin', 'email' => 'xa@example.com', 'role' => 'admin']))->assertStatus(403);
        $this->device($c)->postJson('/api/users', $this->newUserPayload(['username' => 'x.super', 'email' => 'xs@example.com', 'role' => 'super_admin']))->assertStatus(403);
        $this->assertSame(0, User::where('username', 'like', 'x.%')->count());

        $employee = User::where('username', 'new.person')->first();
        $this->device($c)->patchJson("/api/users/{$employee->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->device($c)->patchJson("/api/users/{$employee->id}", ['role' => 'admin'])->assertStatus(403);
        $this->assertSame('employee', $employee->fresh()->role->slug);

        foreach ([Role::ADMIN, Role::SUPER_ADMIN] as $role) {
            $other = $this->makeUser($role);
            $this->device($c)->patchJson("/api/users/{$other->id}", ['name' => 'Hacked'])->assertStatus(403);
            $this->device($c)->postJson("/api/users/{$other->id}/deactivate")->assertStatus(403);
            $this->device($c)->postJson("/api/users/{$other->id}/reset-password", ['password' => 'a-long-test-password'])->assertStatus(403);
            $this->assertTrue($other->fresh()->is_active);
            $this->assertNotSame('Hacked', $other->fresh()->name);
        }
        $this->device($c)->getJson('/api/users')->assertOk(); // may still view the directory
    }

    public function test_super_admin_can_create_every_role_and_change_roles(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        foreach (['employee', 'admin', 'super_admin'] as $i => $role) {
            $this->device($c)->postJson('/api/users', $this->newUserPayload(['username' => "u.$role", 'email' => "$role@example.com", 'role' => $role]))
                ->assertCreated()->assertJsonPath('data.role', $role);
        }
        $emp = User::where('username', 'u.employee')->first();
        $this->device($c)->patchJson("/api/users/{$emp->id}", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_cannot_change_or_deactivate_your_own_account_state(): void
    {
        $actor = $this->makeUser(Role::SUPER_ADMIN);
        [, $c] = $this->signIn($actor);
        $this->makeUser(Role::SUPER_ADMIN); // not the last one

        $this->device($c)->patchJson("/api/users/{$actor->id}", ['role' => 'employee'])->assertStatus(422)->assertJsonPath('error.code', 'cannot_modify_self');
        $this->device($c)->postJson("/api/users/{$actor->id}/deactivate")->assertStatus(422)->assertJsonPath('error.code', 'cannot_modify_self');
        $this->assertSame('super_admin', $actor->fresh()->role->slug);
        $this->assertTrue($actor->fresh()->is_active);
    }

    // ---- create / validate / update ------------------------------------------------------------

    public function test_create_validates_input(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $this->makeUser(attrs: ['username' => 'taken.name', 'email' => 'taken@example.com']);

        $bad = $this->device($c)->postJson('/api/users', ['username' => 'a b', 'name' => '', 'email' => 'nope', 'password' => 'short', 'role' => 'ceo', 'department_ids' => [9999], 'daily_token_limit' => -1])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        foreach (['username', 'name', 'email', 'password', 'role', 'department_ids.0', 'daily_token_limit'] as $field) {
            $this->assertArrayHasKey($field, $bad->json('error.details'), $field);
        }

        $this->device($c)->postJson('/api/users', $this->newUserPayload(['username' => 'taken.name']))->assertStatus(422);
        $this->device($c)->postJson('/api/users', $this->newUserPayload(['email' => 'taken@example.com']))->assertStatus(422);
    }

    public function test_create_stores_departments_token_limit_and_a_hashed_password_without_leaking(): void
    {
        $c = $this->cookieFor(Role::ADMIN);
        [$a, $b] = [Department::create(['name' => 'Ops']), Department::create(['name' => 'Sales'])];

        $r = $this->device($c)->postJson('/api/users', $this->newUserPayload(['department_ids' => [$a->id, $b->id], 'daily_token_limit' => 50000, 'email' => null]))->assertCreated();

        $this->assertStringNotContainsString('a-long-test-password', $r->getContent());
        $this->assertStringNotContainsString('session_id', $r->getContent());
        $this->assertSame(50000, $r->json('data.daily_token_limit'));
        $this->assertCount(2, $r->json('data.departments'));
        $user = User::where('username', 'new.person')->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-long-test-password', $user->password));
        $this->assertNull($user->email);
        $this->assertTrue($user->is_active);
    }

    public function test_update_changes_fields_departments_and_token_limit_but_never_the_username(): void
    {
        $c = $this->cookieFor(Role::ADMIN);
        $user = $this->makeUser(attrs: ['username' => 'fixed.name', 'daily_token_limit' => 100]);
        [$a, $b] = [Department::create(['name' => 'Ops']), Department::create(['name' => 'Sales'])];
        $user->departments()->attach($a->id);

        $this->device($c)->patchJson("/api/users/{$user->id}", ['username' => 'sneaky', 'name' => 'Better Name', 'department_ids' => [$b->id], 'daily_token_limit' => null])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame('fixed.name', $fresh->username);
        $this->assertSame('Better Name', $fresh->name);
        $this->assertNull($fresh->daily_token_limit);
        $this->assertSame([$b->id], $fresh->departments->pluck('id')->all());
    }

    public function test_user_can_be_in_several_departments_and_listing_filters_them(): void
    {
        $c = $this->cookieFor(Role::ADMIN);
        [$ops, $sales] = [Department::create(['name' => 'Ops']), Department::create(['name' => 'Sales'])];
        $both = $this->makeUser(attrs: ['name' => 'Both Depts']);
        $both->departments()->attach([$ops->id, $sales->id]);
        $this->makeUser(attrs: ['name' => 'Ops Only'])->departments()->attach($ops->id);
        $this->makeUser(attrs: ['name' => 'No Dept']);

        $this->assertCount(2, $this->device($c)->getJson("/api/users?department_id={$ops->id}")->json('data'));
        $this->assertCount(1, $this->device($c)->getJson("/api/users?department_id={$sales->id}")->json('data'));
    }

    public function test_listing_supports_search_role_active_filters_and_pagination(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        foreach (range(1, 7) as $i) {
            $this->makeUser(attrs: ['name' => "Person $i", 'username' => "person$i", 'email' => "p$i@example.com"]);
        }
        $this->makeUser(Role::ADMIN, ['name' => 'Boss', 'username' => 'boss']);
        $this->makeUser(attrs: ['name' => 'Gone', 'username' => 'gone', 'is_active' => false]);

        $page = $this->device($c)->getJson('/api/users?per_page=3&role=employee&active=1&search=person')->assertOk();
        $this->assertCount(3, $page->json('data'));
        $this->assertSame(7, $page->json('meta.total'));
        $this->assertSame(3, $page->json('meta.last_page'));
        $this->assertSame(['boss'], collect($this->device($c)->getJson('/api/users?role=admin&search=boss')->json('data'))->pluck('username')->all());
        $this->assertSame(['gone'], collect($this->device($c)->getJson('/api/users?active=0')->json('data'))->pluck('username')->all());
        $this->assertStringNotContainsString('"password"', $page->getContent());
        $this->device($c)->getJson('/api/users?per_page=1000')->assertStatus(422);
    }

    // ---- deactivation --------------------------------------------------------------------------

    public function test_deactivation_blocks_sign_in_ends_the_session_and_keeps_history(): void
    {
        $c = $this->cookieFor(Role::ADMIN);
        $target = $this->makeUser();
        $dept = Department::create(['name' => 'Ops']);
        $target->departments()->attach($dept->id);
        [, $tc] = $this->signIn($target);
        $this->device($tc)->getJson('/api/auth/me')->assertOk();

        $this->device($c)->postJson("/api/users/{$target->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);

        $this->device($tc)->getJson('/api/auth/me')->assertStatus(401);
        [$login] = $this->signIn($target);
        $login->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');

        $fresh = $target->fresh();
        $this->assertNotNull($fresh->deactivated_at);
        $this->assertNull($fresh->session_id);
        $this->assertCount(1, $fresh->departments);
        $this->assertGreaterThan(0, AuditEntry::where('actor_id', $target->id)->count(), 'earlier audit history remains');
        $this->assertSame(1, User::whereKey($target->id)->count(), 'the account is never deleted');
    }

    public function test_reactivation_restores_sign_in(): void
    {
        $c = $this->cookieFor(Role::ADMIN);
        $target = $this->makeUser(attrs: ['is_active' => false, 'deactivated_at' => now()]);

        $this->device($c)->postJson("/api/users/{$target->id}/reactivate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertNull($target->fresh()->deactivated_at);
        [$r] = $this->signIn($target);
        $r->assertOk();
    }

    // ---- passwords -----------------------------------------------------------------------------

    public function test_privileged_reset_changes_the_password_signs_the_user_out_and_never_echoes_it(): void
    {
        $c = $this->cookieFor(Role::ADMIN);
        $target = $this->makeUser();
        [, $tc] = $this->signIn($target);

        $r = $this->device($c)->postJson("/api/users/{$target->id}/reset-password", ['password' => 'brand-new-temp-password'])->assertOk();
        $this->assertStringNotContainsString('brand-new-temp-password', $r->getContent());

        $this->device($tc)->getJson('/api/auth/me')->assertStatus(401);
        [$old] = $this->signIn($target, null, self::PW);
        $old->assertStatus(401);
        [$new] = $this->signIn($target, null, 'brand-new-temp-password');
        $new->assertOk();

        $entry = AuditEntry::where('action', 'account.password_reset')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('brand-new-temp-password', json_encode($entry->toArray()));

        $this->device($c)->postJson("/api/users/{$target->id}/reset-password", ['password' => 'short'])->assertStatus(422);
    }

    public function test_own_password_change_requires_the_current_password_and_a_new_one(): void
    {
        $user = $this->makeUser();
        [, $c] = $this->signIn($user);
        $new = 'a-completely-new-password';

        $this->device($c)->postJson('/api/account/password', ['current_password' => 'wrong-wrong-wrong', 'password' => $new, 'password_confirmation' => $new])
            ->assertStatus(422)->assertJsonPath('error.details.current_password.0', 'The current password is incorrect.');
        $this->device($c)->postJson('/api/account/password', ['current_password' => self::PW, 'password' => $new, 'password_confirmation' => 'different-different'])->assertStatus(422);
        $this->device($c)->postJson('/api/account/password', ['current_password' => self::PW, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->device($c)->postJson('/api/account/password', ['current_password' => self::PW, 'password' => self::PW, 'password_confirmation' => self::PW])->assertStatus(422);
        [$still] = $this->signIn($user);
        $still->assertOk(); // nothing changed yet

        [, $c] = $this->signIn($user);
        $r = $this->device($c)->postJson('/api/account/password', ['current_password' => self::PW, 'password' => $new, 'password_confirmation' => $new])->assertOk();
        $this->assertStringNotContainsString($new, $r->getContent());

        $this->device($c)->getJson('/api/auth/me')->assertOk(); // stays signed in
        [$oldPw] = $this->signIn($user, null, self::PW);
        $oldPw->assertStatus(401);
        [$newPw] = $this->signIn($user, null, $new);
        $newPw->assertOk();
        $this->assertSame(1, AuditEntry::where('action', 'account.password_changed')->where('actor_id', $user->id)->count());
    }

    // ---- departments ---------------------------------------------------------------------------

    public function test_department_crud_and_in_use_protection(): void
    {
        $c = $this->cookieFor(Role::ADMIN);

        $id = $this->device($c)->postJson('/api/departments', ['name' => 'Finance', 'description' => 'Money'])->assertCreated()->json('data.id');
        $this->device($c)->postJson('/api/departments', ['name' => 'Finance'])->assertStatus(422);
        $this->device($c)->patchJson("/api/departments/$id", ['name' => 'Finance & Accounting'])->assertOk()->assertJsonPath('data.name', 'Finance & Accounting');
        $this->makeUser()->departments()->attach($id);

        $list = $this->device($c)->getJson('/api/departments')->assertOk();
        $this->assertSame(1, $list->json('data.0.members'));
        $this->device($c)->deleteJson("/api/departments/$id")->assertStatus(422)->assertJsonPath('error.code', 'department_in_use');

        \DB::table('department_user')->where('department_id', $id)->delete();
        $this->device($c)->deleteJson("/api/departments/$id")->assertOk();
        $this->assertSame(0, Department::count());
    }

    // ---- audit ---------------------------------------------------------------------------------

    public function test_account_changes_are_audited_with_actor_target_and_no_secrets(): void
    {
        $actor = $this->makeUser(Role::SUPER_ADMIN);
        [, $c] = $this->signIn($actor);

        $id = $this->device($c)->postJson('/api/users', $this->newUserPayload())->json('data.id');
        $this->device($c)->patchJson("/api/users/$id", ['name' => 'Changed Name'])->assertOk();
        $this->device($c)->patchJson("/api/users/$id", ['name' => 'Changed Name'])->assertOk(); // no-op: no second entry
        $this->device($c)->postJson("/api/users/$id/deactivate")->assertOk();
        $this->device($c)->postJson("/api/users/$id/reactivate")->assertOk();
        $dept = $this->device($c)->postJson('/api/departments', ['name' => 'Legal'])->json('data.id');
        $this->device($c)->deleteJson("/api/departments/$dept")->assertOk();

        $actions = AuditEntry::where('actor_id', $actor->id)->where('action', 'not like', 'auth.%')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['account.created', 'account.updated', 'account.deactivated', 'account.reactivated', 'department.created', 'department.deleted'], $actions);

        $created = AuditEntry::where('action', 'account.created')->first();
        $this->assertSame($actor->username, $created->actor_username);
        $this->assertSame($id, $created->target_id);
        $this->assertSame('User', $created->target_type);
        $this->assertSame(['name'], AuditEntry::where('action', 'account.updated')->first()->metadata['fields']);
        $this->assertStringNotContainsString('a-long-test-password', json_encode(AuditEntry::all()->toArray()));
    }

    public function test_the_last_active_super_admin_cannot_be_deactivated_or_demoted(): void
    {
        // The only actor allowed to touch a Super Admin is another Super Admin, so build that situation directly:
        // two super admins, then the second is deactivated by the first, leaving the first as the only one.
        $a = $this->makeUser(Role::SUPER_ADMIN);
        $b = $this->makeUser(Role::SUPER_ADMIN);
        [, $c] = $this->signIn($a);

        $this->device($c)->postJson("/api/users/{$b->id}/deactivate")->assertOk(); // fine: a remains

        // Now a (the actor) is the last one; the self-protection rule is what stops them.
        $this->device($c)->postJson("/api/users/{$a->id}/deactivate")->assertStatus(422);
        $this->device($c)->patchJson("/api/users/{$a->id}", ['role' => 'admin'])->assertStatus(422);
        $this->assertTrue($a->fresh()->is_active);
        $this->assertSame('super_admin', $a->fresh()->role->slug);
    }
}
