<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Department;
use App\Models\PolicyAcknowledgment;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function assertRejected(callable $fn, string $why): void
    {
        try {
            $fn();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail($why);
    }

    public function test_all_core_tables_exist_and_no_reset_token_table(): void
    {
        foreach (['roles', 'users', 'departments', 'department_user', 'sessions', 'settings', 'policy_acknowledgments', 'audit_entries'] as $t) {
            $this->assertTrue(Schema::hasTable($t), $t);
        }
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
    }

    public function test_seeder_is_repeatable_and_creates_no_users(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, Role::count());
        $this->assertEqualsCanonicalizing(array_keys(Role::ALL), Role::pluck('slug')->all());
        $this->assertSame(0, User::count());
    }

    public function test_migrations_rerun_cleanly_from_scratch(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();
        $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();
        $this->assertSame(3, Role::count());
    }

    public function test_multiple_departments_per_user_and_users_per_department(): void
    {
        $user = User::factory()->create();
        $ops = Department::create(['name' => 'Operations']);
        $sales = Department::create(['name' => 'Sales']);
        $user->departments()->attach([$ops->id, $sales->id]);
        User::factory()->create()->departments()->attach($ops->id);

        $this->assertCount(2, $user->fresh()->departments);
        $this->assertCount(2, $ops->users);
    }

    public function test_duplicate_department_membership_is_rejected(): void
    {
        $user = User::factory()->create();
        $dept = Department::create(['name' => 'Ops']);
        $user->departments()->attach($dept->id);

        $this->assertRejected(fn () => $user->departments()->attach($dept->id), 'duplicate membership allowed');
    }

    public function test_unique_username_and_email(): void
    {
        User::factory()->create(['username' => 'jdoe', 'email' => 'a@example.com']);

        $this->assertRejected(fn () => User::factory()->create(['username' => 'jdoe']), 'duplicate username allowed');
        $this->assertRejected(fn () => User::factory()->create(['email' => 'a@example.com']), 'duplicate email allowed');
    }

    public function test_email_is_optional_and_multiple_nulls_allowed(): void
    {
        User::factory()->create(['email' => null]);
        User::factory()->create(['email' => null]);
        $this->assertSame(2, User::whereNull('email')->count());
    }

    public function test_cannot_delete_role_or_user_that_has_history(): void
    {
        $user = User::factory()->create();
        PolicyAcknowledgment::create(['user_id' => $user->id, 'policy_version' => '1', 'acknowledged_at' => now()]);
        AuditEntry::create(['actor_id' => $user->id, 'actor_username' => $user->username, 'action' => 'test']);

        $this->assertRejected(fn () => $user->delete(), 'user with history was deleted');
        $this->assertRejected(fn () => $user->role->delete(), 'role in use was deleted');
        $this->assertSame(1, PolicyAcknowledgment::count());
        $this->assertSame(1, AuditEntry::count());
    }

    public function test_deactivation_keeps_the_account_and_related_records(): void
    {
        $user = User::factory()->create();
        $dept = Department::create(['name' => 'Ops']);
        $user->departments()->attach($dept->id);
        AuditEntry::create(['actor_id' => $user->id, 'action' => 'login']);

        $user->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertCount(1, $user->fresh()->departments);
        $this->assertSame(1, AuditEntry::where('actor_id', $user->id)->count());
    }

    public function test_department_in_use_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $dept = Department::create(['name' => 'Ops']);
        $user->departments()->attach($dept->id);

        $this->assertRejected(fn () => $dept->delete(), 'department with members was deleted');
    }

    public function test_policy_acknowledgment_is_unique_per_user_and_version(): void
    {
        $user = User::factory()->create();
        PolicyAcknowledgment::create(['user_id' => $user->id, 'policy_version' => '1', 'acknowledged_at' => now()]);
        PolicyAcknowledgment::create(['user_id' => $user->id, 'policy_version' => '2', 'acknowledged_at' => now()]);

        $this->assertRejected(
            fn () => PolicyAcknowledgment::create(['user_id' => $user->id, 'policy_version' => '1', 'acknowledged_at' => now()]),
            'duplicate acknowledgment allowed',
        );
    }

    public function test_settings_key_is_unique_and_value_roundtrips_json(): void
    {
        Setting::create(['key' => 'idle_minutes', 'value' => 30]);
        $this->assertSame(30, Setting::where('key', 'idle_minutes')->first()->value);
        Setting::create(['key' => 'file_limits', 'value' => ['count' => 6, 'mb' => 15]]);
        $this->assertSame(15, Setting::where('key', 'file_limits')->first()->value['mb']);

        $this->assertRejected(fn () => Setting::create(['key' => 'idle_minutes', 'value' => 10]), 'duplicate setting key allowed');
    }

    public function test_audit_entry_stores_actor_target_metadata_and_time(): void
    {
        $user = User::factory()->create();
        $e = AuditEntry::create([
            'actor_id' => $user->id, 'actor_username' => $user->username, 'action' => 'account.updated',
            'target_type' => 'user', 'target_id' => 7, 'metadata' => ['fields' => ['name']], 'ip_address' => '127.0.0.1',
        ])->fresh();

        $this->assertSame($user->id, $e->actor->id);
        $this->assertSame(['fields' => ['name']], $e->metadata);
        $this->assertNotNull($e->created_at);
    }

    public function test_role_and_active_flag_are_not_mass_assignable_and_password_is_hashed(): void
    {
        $admin = Role::create(['slug' => Role::ADMIN, 'name' => 'Admin']);
        Role::create(['slug' => Role::EMPLOYEE, 'name' => 'Employee']);

        // role_id is guarded: mass assignment ignores it, so the column default would fail; set it explicitly.
        $user = new User(['username' => 'x1', 'name' => 'X', 'password' => 'secret-pw', 'role_id' => $admin->id, 'is_active' => false]);
        $this->assertNull($user->role_id);
        $this->assertNull($user->getAttribute('is_active'));

        $user->role_id = $admin->id;
        $user->save();
        $this->assertTrue($user->fresh()->is_active);
        $this->assertNotSame('secret-pw', $user->fresh()->password);
        $this->assertTrue(Hash::check('secret-pw', $user->fresh()->password));
        $this->assertArrayNotHasKey('password', $user->fresh()->toArray());
    }
}
