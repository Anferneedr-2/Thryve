<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class SettingsAndAuditTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    // ---- access --------------------------------------------------------------------------------

    public function test_only_super_admin_can_read_or_write_settings_over_http(): void
    {
        $this->device(null)->getJson('/api/settings')->assertStatus(401);
        $this->device(null)->patchJson('/api/settings', ['idle_minutes' => 20])->assertStatus(401);

        foreach ([Role::EMPLOYEE, Role::ADMIN] as $role) {
            $c = $this->cookieFor($role);
            $this->device($c)->getJson('/api/settings')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
            $this->device($c)->patchJson('/api/settings', ['idle_minutes' => 20])->assertStatus(403);
        }
        $this->assertSame(0, Setting::count(), 'nothing was written by unauthorized callers');

        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $this->device($c)->getJson('/api/settings')->assertOk();
        $this->device($c)->patchJson('/api/settings', ['idle_minutes' => 20])->assertOk();
    }

    public function test_service_refuses_writes_from_anyone_but_a_super_admin(): void
    {
        $admin = $this->makeUser(Role::ADMIN);
        $this->expectException(AuthorizationException::class);
        Settings::update(['idle_minutes' => 5], $admin);
    }

    // ---- reading -------------------------------------------------------------------------------

    public function test_defaults_apply_until_a_value_is_saved(): void
    {
        $this->assertSame(30, Settings::get('idle_minutes'));
        $this->assertSame(15, Settings::get('analysis_idle_minutes'));
        $this->assertSame(6, Settings::get('file_max_count'));
        $this->assertSame(15, Settings::get('file_max_mb'));
        $this->assertSame('warn', Settings::get('sensitive_data_mode'));

        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $all = $this->device($c)->getJson('/api/settings')->assertOk()->json('data');
        $this->assertSame(30, $all['idle_minutes']['value']);
        $this->assertFalse($all['idle_minutes']['is_set']);
        $this->assertSame('Attendance idle threshold (minutes)', $all['idle_minutes']['label']);
    }

    public function test_unknown_setting_cannot_be_read(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Settings::get('nope');
    }

    // ---- writing -------------------------------------------------------------------------------

    public function test_update_persists_typed_values_and_reports_what_changed(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($c)->patchJson('/api/settings', ['idle_minutes' => 45, 'sensitive_data_mode' => 'block', 'company_name' => 'Acme'])->assertOk();

        $this->assertEqualsCanonicalizing(['idle_minutes', 'sensitive_data_mode', 'company_name'], $r->json('data.changed'));
        $this->assertSame(45, Settings::get('idle_minutes'));
        $this->assertSame('block', Settings::get('sensitive_data_mode'));
        $this->assertSame('Acme', Settings::get('company_name'));
        $this->assertTrue($r->json('data.settings.idle_minutes.is_set'));
        $this->assertNotNull(Setting::where('key', 'idle_minutes')->value('updated_by'));
    }

    public function test_invalid_values_are_rejected_and_nothing_is_partially_saved(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($c)->patchJson('/api/settings', ['idle_minutes' => 20, 'file_max_mb' => 5000, 'sensitive_data_mode' => 'maybe'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertArrayHasKey('file_max_mb', $r->json('error.details'));
        $this->assertArrayHasKey('sensitive_data_mode', $r->json('error.details'));
        $this->assertSame(30, Settings::get('idle_minutes'), 'the valid value was not saved either');
        $this->assertSame(0, Setting::count());

        foreach ([['idle_minutes' => 0], ['idle_minutes' => 'abc'], ['file_max_count' => 21], ['company_name' => ''], ['company_name' => str_repeat('x', 101)]] as $bad) {
            $this->device($c)->patchJson('/api/settings', $bad)->assertStatus(422);
        }
    }

    public function test_unknown_keys_and_empty_requests_are_rejected(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($c)->patchJson('/api/settings', ['not_a_setting' => 1])->assertStatus(422)->assertJsonPath('error.details.not_a_setting.0', 'Unknown setting.');
        $this->device($c)->patchJson('/api/settings', [])->assertStatus(422);
        $this->assertSame(0, Setting::count());
    }

    // ---- audit of settings ---------------------------------------------------------------------

    public function test_settings_changes_are_audited_with_before_and_after_values(): void
    {
        $actor = $this->makeUser(Role::SUPER_ADMIN);
        [, $c] = $this->signIn($actor);

        $this->device($c)->patchJson('/api/settings', ['idle_minutes' => 45])->assertOk();
        $this->device($c)->patchJson('/api/settings', ['idle_minutes' => 45])->assertOk(); // unchanged: no new entry
        $this->device($c)->patchJson('/api/settings', ['idle_minutes' => 60, 'file_max_mb' => 15])->assertOk(); // file_max_mb equals default: not a change

        $entries = AuditEntry::where('action', 'settings.updated')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['idle_minutes' => ['from' => 30, 'to' => 45]], $entries[0]->metadata['changes']);
        $this->assertSame(['idle_minutes' => ['from' => 45, 'to' => 60]], $entries[1]->metadata['changes']);
        $this->assertSame($actor->id, $entries[0]->actor_id);
        $this->assertSame($actor->username, $entries[0]->actor_username);
        $this->assertNotNull($entries[0]->created_at);
    }

    public function test_secret_settings_are_never_returned_or_audited(): void
    {
        config(['thryve.settings.smtp_password' => ['default' => null, 'rules' => ['nullable', 'string'], 'label' => 'SMTP password', 'secret' => true]]);
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($c)->patchJson('/api/settings', ['smtp_password' => 'hunter2-super-secret'])->assertOk();

        $this->assertStringNotContainsString('hunter2-super-secret', $r->getContent());
        $this->assertNull($r->json('data.settings.smtp_password.value'));
        $this->assertTrue($r->json('data.settings.smtp_password.is_set'));
        $this->assertStringNotContainsString('hunter2-super-secret', json_encode(AuditEntry::all()->toArray()));
        $meta = AuditEntry::where('action', 'settings.updated')->first()->metadata;
        $this->assertSame(['smtp_password'], $meta['protected_changed'], 'the audit log says a secret changed, without its value');
        $this->assertArrayNotHasKey('changes', $meta);
        $this->assertStringNotContainsString('hunter2-super-secret', $this->device($c)->getJson('/api/settings')->getContent());
    }

    // ---- audit writer --------------------------------------------------------------------------

    public function test_audit_entries_record_actor_action_target_time_and_ip(): void
    {
        $actor = $this->makeUser(Role::ADMIN);
        $target = $this->makeUser();

        $e = Audit::log('example.action', $actor, $target, ['note' => 'ok'], '203.0.113.9')->fresh();

        $this->assertSame($actor->id, $e->actor_id);
        $this->assertSame($actor->username, $e->actor_username);
        $this->assertSame('User', $e->target_type);
        $this->assertSame($target->id, $e->target_id);
        $this->assertSame('203.0.113.9', $e->ip_address);
        $this->assertSame(['note' => 'ok'], $e->metadata);
        $this->assertNotNull($e->created_at);
    }

    public function test_system_events_can_have_no_actor(): void
    {
        $e = Audit::log('system.something')->fresh();
        $this->assertNull($e->actor_id);
        $this->assertNull($e->actor_username);
    }

    public function test_audit_never_stores_secret_looking_metadata(): void
    {
        $e = Audit::log('x.y', null, null, ['reason' => 'ok', 'password' => 'p', 'api_key' => 'k', 'Authorization' => 'Bearer z', 'nested' => ['token' => 't', 'keep' => 1]])->fresh();
        $this->assertSame(['reason' => 'ok', 'nested' => ['keep' => 1]], $e->metadata);
    }

    public function test_audit_entries_are_append_only_unless_explicitly_allowed(): void
    {
        $e = Audit::log('keep.me');

        try {
            $e->update(['action' => 'tampered']);
            $this->fail('update was allowed');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        try {
            $e->delete();
            $this->fail('delete was allowed');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('keep.me', $e->fresh()->action);

        AuditEntry::withMutation(fn () => $e->update(['action' => 'corrected']));
        $this->assertSame('corrected', $e->fresh()->action);
        AuditEntry::withMutation(fn () => $e->delete());
        $this->assertSame(0, AuditEntry::count());

        // The permission is scoped: it is switched off again afterwards, even if the callback throws.
        $again = Audit::log('another');
        try {
            AuditEntry::withMutation(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $this->expectException(LogicException::class);
        $again->delete();
    }

    public function test_no_http_route_can_edit_or_delete_audit_entries_yet(): void
    {
        $entry = Audit::log('immutable');
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        foreach (['patch', 'put', 'delete'] as $method) {
            $this->device($c)->json($method, "/api/audit/{$entry->id}", [])->assertStatus(404);
        }
        $this->assertSame('immutable', $entry->fresh()->action);
    }
}
