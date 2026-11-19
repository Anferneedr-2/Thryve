<?php

namespace Tests\Feature;

use App\Ai\Ledger;
use App\Models\AuditEntry;
use App\Models\Notification;
use App\Models\PriceEntry;
use App\Models\ProviderConnection;
use App\Models\Role;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

class ProviderApiTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function create(string $cookie, array $o = [])
    {
        return $this->device($cookie)->postJson('/api/providers', $o + ['name' => 'Company Claude', 'kind' => 'claude', 'credentials' => 'sk-live-very-secret-123']);
    }

    private function conn(string $name, int $priority, array $attrs = []): ProviderConnection
    {
        return ProviderConnection::create(['name' => $name, 'kind' => 'claude', 'priority' => $priority, 'credentials' => 'secret-'.$name] + $attrs);
    }

    // ---- access --------------------------------------------------------------------------------

    public function test_admins_can_look_but_only_a_super_admin_can_change_anything(): void
    {
        $p = $this->conn('one', 10);
        $reads = [['get', '/api/providers'], ['get', '/api/prices'], ['get', '/api/usage/summary']];
        $writes = [['post', '/api/providers'], ['post', '/api/providers/reorder'], ['patch', "/api/providers/{$p->id}"], ['post', "/api/providers/{$p->id}/reauthenticate"],
            ['post', "/api/providers/{$p->id}/check"], ['post', "/api/providers/{$p->id}/quota"], ['delete', "/api/providers/{$p->id}"], ['post', '/api/prices']];

        foreach (array_merge($reads, $writes) as [$m, $url]) {
            $this->device(null)->json($m, $url, [])->assertStatus(401);
        }
        $employee = $this->cookieFor(Role::EMPLOYEE);
        foreach (array_merge($reads, $writes) as [$m, $url]) {
            $this->device($employee)->json($m, $url, [])->assertStatus(403);
        }
        $admin = $this->cookieFor(Role::ADMIN);
        foreach ($reads as [$m, $url]) {
            $this->device($admin)->json($m, $url)->assertOk();
        }
        foreach ($writes as [$m, $url]) {
            $this->device($admin)->json($m, $url, ['name' => 'x', 'kind' => 'claude', 'credentials' => 'x'])->assertStatus(403);
        }
        $this->assertSame('one', $p->fresh()->name);
        $this->assertNull($p->fresh()->removed_at);
    }

    // ---- create and secrets --------------------------------------------------------------------

    public function test_credentials_are_encrypted_at_rest_and_never_returned_or_logged(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->create($c)->assertCreated();

        $raw = DB::table('provider_connections')->value('credentials');
        $this->assertStringNotContainsString('sk-live-very-secret-123', $raw);
        $this->assertSame('sk-live-very-secret-123', ProviderConnection::first()->credentials, 'usable by the server');
        $this->assertTrue($r->json('data.has_credentials'));
        $this->assertStringNotContainsString('sk-live', $r->getContent());
        $admin = $this->cookieFor(Role::ADMIN);
        $this->assertStringNotContainsString('sk-live', $this->device($admin)->getJson('/api/providers')->getContent());
        $this->assertStringNotContainsString('sk-live', json_encode(AuditEntry::all()->toArray()));
        $this->assertStringNotContainsString('sk-live', json_encode(ProviderConnection::first()->toArray()));
    }

    public function test_creating_a_connection_is_validated_audited_and_gets_the_next_priority(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $this->create($c, ['name' => ''])->assertStatus(422);
        $this->create($c, ['kind' => 'gemini'])->assertStatus(422);
        $this->create($c, ['credentials' => ''])->assertStatus(422);
        $this->create($c, ['priority' => 0])->assertStatus(422);
        $this->assertSame(0, ProviderConnection::count());

        $a = $this->create($c, ['name' => 'First'])->assertCreated();
        $b = $this->create($c, ['name' => 'Second', 'kind' => 'chatgpt'])->assertCreated();
        $this->assertSame([10, 20], [$a->json('data.priority'), $b->json('data.priority')]);
        $this->assertSame('unknown', $a->json('data.status'));
        $this->assertSame(['provider_id', 'name', 'kind'], array_keys(AuditEntry::where('action', 'provider.created')->first()->metadata));
    }

    public function test_the_list_shows_status_quota_and_todays_usage_without_inventing_figures(): void
    {
        $known = $this->conn('with-quota', 10, ['quota_known' => true, 'quota_limit' => 1000, 'quota_used' => 250, 'status' => 'available']);
        $this->conn('no-figure', 20, ['quota_known' => false, 'quota_limit' => 5, 'quota_used' => 5]);
        $employee = \App\Models\User::factory()->create();
        $r = app(Ledger::class)->begin($employee, $known, 'r-1', 1, 'm1');
        app(Ledger::class)->finish($r, 'success', 70, 30);
        $r2 = app(Ledger::class)->begin($employee, $known, 'r-2', 1, 'm1');
        app(Ledger::class)->finish($r2, 'failed', 0, 0, failure: 'unavailable');

        $rows = $this->device($this->cookieFor(Role::ADMIN))->getJson('/api/providers')->assertOk()->json('data');

        $this->assertSame(['with-quota', 'no-figure'], array_column($rows, 'name'));
        $this->assertSame(['known' => true, 'limit' => 1000, 'used' => 250, 'resets_at' => null, 'percent' => 25], $rows[0]['quota']);
        $this->assertSame(['known' => false, 'limit' => null, 'used' => null, 'resets_at' => null, 'percent' => null], $rows[1]['quota'], 'no figure from the provider means no number is shown');
        $this->assertSame(['tokens' => 100, 'requests' => 2, 'failures' => 1], $rows[0]['usage_today']);
        $this->assertSame(['tokens' => 0, 'requests' => 0, 'failures' => 0], $rows[1]['usage_today']);
    }

    // ---- changing a connection -----------------------------------------------------------------

    public function test_a_connection_can_be_renamed_disabled_and_enabled(): void
    {
        $p = $this->conn('one', 10);
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($c)->patchJson("/api/providers/{$p->id}", ['name' => 'Renamed', 'is_enabled' => false])->assertOk()->assertJsonPath('data.is_enabled', false);
        $this->assertFalse($p->fresh()->is_enabled);
        $this->assertSame([], app(\App\Ai\ProviderRouter::class)->eligible()->all(), 'a disabled connection takes no traffic');
        $this->device($c)->patchJson("/api/providers/{$p->id}", ['is_enabled' => true])->assertOk();
        $this->assertCount(1, app(\App\Ai\ProviderRouter::class)->eligible());
        $this->device($c)->patchJson("/api/providers/{$p->id}", ['name' => str_repeat('x', 101)])->assertStatus(422);
        $this->assertSame(2, AuditEntry::where('action', 'provider.updated')->count());
    }

    public function test_reordering_sets_the_priority_and_requires_every_connection_once(): void
    {
        [$a, $b, $c3] = [$this->conn('a', 10), $this->conn('b', 20), $this->conn('c', 30)];
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($c)->postJson('/api/providers/reorder', ['ids' => [$c3->id, $a->id]])->assertStatus(422);
        $this->device($c)->postJson('/api/providers/reorder', ['ids' => [$c3->id, $a->id, $b->id, $b->id]])->assertStatus(422);
        $this->device($c)->postJson('/api/providers/reorder', ['ids' => [$c3->id, $a->id, 999]])->assertStatus(422);
        $this->assertSame(['a', 'b', 'c'], ProviderConnection::orderBy('priority')->pluck('name')->all(), 'a rejected order changes nothing');

        $r = $this->device($c)->postJson('/api/providers/reorder', ['ids' => [$c3->id, $a->id, $b->id]])->assertOk();

        $this->assertSame(['c', 'a', 'b'], array_column($r->json('data'), 'name'));
        $this->assertSame('c', app(\App\Ai\ProviderRouter::class)->eligible()->first()->name);
    }

    public function test_reconnecting_replaces_the_credentials_and_clears_the_problem(): void
    {
        $p = $this->conn('one', 10, ['status' => 'reauth_required', 'last_error' => 'unauthorized']);
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($c)->postJson("/api/providers/{$p->id}/reauthenticate", ['credentials' => 'brand-new-secret'])->assertOk();

        $this->assertSame('unknown', $p->fresh()->status);
        $this->assertNull($p->fresh()->last_error);
        $this->assertSame('brand-new-secret', $p->fresh()->credentials);
        $this->assertStringNotContainsString('brand-new-secret', $r->getContent());
        $this->assertCount(1, app(\App\Ai\ProviderRouter::class)->eligible(), 'back in rotation');
        $this->assertStringNotContainsString('brand-new-secret', json_encode(AuditEntry::all()->toArray()));
        $this->device($c)->postJson("/api/providers/{$p->id}/reauthenticate", [])->assertStatus(422);
    }

    public function test_removing_a_connection_keeps_its_usage_history_and_wipes_its_credentials(): void
    {
        $p = $this->conn('one', 10);
        $u = \App\Models\User::factory()->create();
        $rec = app(Ledger::class)->begin($u, $p, 'r-1', 1, 'm1');
        app(Ledger::class)->finish($rec, 'success', 10, 10);
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $this->device($c)->deleteJson("/api/providers/{$p->id}")->assertOk();

        $this->assertNotNull($p->fresh()->removed_at);
        $this->assertNull($p->fresh()->credentials);
        $this->assertFalse($p->fresh()->is_enabled);
        $this->assertSame(1, UsageRecord::count(), 'history is never erased');
        $this->assertSame([], $this->device($c)->getJson('/api/providers')->json('data'));
        $this->device($c)->deleteJson("/api/providers/{$p->id}")->assertStatus(404);
        $this->device($c)->patchJson("/api/providers/{$p->id}", ['name' => 'x'])->assertStatus(404);
        $this->assertSame(1, AuditEntry::where('action', 'provider.removed')->count());
    }

    // ---- checking ------------------------------------------------------------------------------

    public function test_checking_without_hermes_says_so_and_changes_nothing(): void
    {
        config(['services.hermes.url' => null]);
        $p = $this->conn('one', 10);
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($c)->postJson("/api/providers/{$p->id}/check")->assertOk();

        $this->assertFalse($r->json('data.checked'));
        $this->assertSame('unknown', $p->fresh()->status);
    }

    public function test_a_check_updates_the_status_and_alerts_admins_when_it_goes_bad(): void
    {
        config(['services.hermes.url' => 'https://hermes.example.com', 'services.hermes.key' => 'k']);
        $p = $this->conn('one', 10);
        $admin = \App\Models\User::factory()->role(Role::ADMIN)->create();
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        Http::fake(['*' => Http::sequence()->pushStatus(200)->pushStatus(401)->pushStatus(200)]);
        $this->device($c)->postJson("/api/providers/{$p->id}/check")->assertOk()->assertJsonPath('data.provider.status', 'available');

        $this->device($c)->postJson("/api/providers/{$p->id}/check")->assertOk()->assertJsonPath('data.provider.status', 'reauth_required');
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'provider.reauth')->count());

        $this->device($c)->postJson("/api/providers/{$p->id}/check")->assertOk();
        $this->assertSame('reauth_required', $p->fresh()->status, 'a good ping does not hide rejected credentials');
    }

    // ---- quota alerts --------------------------------------------------------------------------

    public function test_recording_quota_alerts_admins_once_each_at_80_90_and_100_and_sets_a_full_connection_aside(): void
    {
        $admin = \App\Models\User::factory()->role(Role::ADMIN)->create();
        $p = $this->conn('one', 10);
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $set = fn (int $used) => $this->device($c)->postJson("/api/providers/{$p->id}/quota", ['limit' => 1000, 'used' => $used, 'resets_at' => '2026-12-01 00:00:00'])->assertOk();

        $set(700);
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());
        $set(800);
        $set(850);
        $this->assertSame(1, Notification::where('user_id', $admin->id)->count(), 'once for 80%');
        $set(950);
        $this->assertSame(2, Notification::where('user_id', $admin->id)->count());
        $r = $set(1000);
        $this->assertSame(3, Notification::where('user_id', $admin->id)->count());
        $this->assertSame('quota_exhausted', $r->json('data.status'));
        $this->assertSame(100, $r->json('data.quota.percent'));
        $this->assertSame([], app(\App\Ai\ProviderRouter::class)->eligible()->all());

        $set(100);
        $this->assertSame('unknown', $p->fresh()->status, 'room again');
        $this->device($c)->postJson("/api/providers/{$p->id}/quota", ['limit' => 0, 'used' => 0])->assertStatus(422);
        $this->device($c)->postJson("/api/providers/{$p->id}/quota", ['limit' => 10, 'used' => -1])->assertStatus(422);
    }

    // ---- prices --------------------------------------------------------------------------------

    public function test_prices_are_added_never_edited_and_validated(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $body = ['kind' => 'claude', 'model' => 'claude-x-1', 'input_per_million' => '3', 'output_per_million' => '15.5', 'currency' => 'usd'];

        $r = $this->device($c)->postJson('/api/prices', $body)->assertCreated();
        $this->assertSame(['3.0000', '15.5000', 'USD', true], [$r->json('data.input_per_million'), $r->json('data.output_per_million'), $r->json('data.currency'), $r->json('data.in_force')]);

        $this->device($c)->postJson('/api/prices', ['input_per_million' => '4'] + $body)->assertCreated();
        $this->device($c)->postJson('/api/prices', ['effective_from' => now()->addDays(5)->toIso8601String(), 'input_per_million' => '9'] + $body)->assertCreated();
        $rows = $this->device($this->cookieFor(Role::ADMIN))->getJson('/api/prices')->assertOk()->json('data');
        $this->assertCount(3, $rows, 'the old prices are still there');
        $this->assertContains(false, array_column($rows, 'in_force'), 'a future price is listed but not in force yet');
        $this->assertSame(3, PriceEntry::count());

        foreach ([['kind' => 'gemini'], ['model' => 'bad model!'], ['input_per_million' => '-1'], ['output_per_million' => 'abc'], ['currency' => 'US'], ['currency' => '123'], ['input_per_million' => '99999999']] as $bad) {
            $this->device($c)->postJson('/api/prices', $bad + $body)->assertStatus(422);
        }
        $this->assertSame(3, PriceEntry::count());
        $this->assertSame(3, AuditEntry::where('action', 'price.added')->count());
    }

    // ---- usage ---------------------------------------------------------------------------------

    public function test_the_usage_summary_groups_by_provider_and_flags_estimates_and_unpriced_requests(): void
    {
        app(\App\Ai\PriceBook::class)->add('claude', 'priced', '10.0000', '10.0000', 'USD', now()->subDay(), null);
        [$a, $b] = [$this->conn('alpha', 10), $this->conn('beta', 20)];
        $u = \App\Models\User::factory()->create(['daily_token_limit' => 10_000_000]);
        $L = app(Ledger::class);
        $L->finish($L->begin($u, $a, 'r1', 1, 'priced'), 'success', 1_000_000, 0, latencyMs: 100);
        $L->finish($L->begin($u, $a, 'r2', 1, 'priced'), 'failed', 0, 0, latencyMs: 300, failure: 'unavailable');
        $L->finish($L->begin($u, $b, 'r3', 1, 'unpriced-model'), 'success', 500, 500, true, 50);
        $L->begin($u, $b, 'r4', 1, 'priced', estimate: 999); // still running: not in the summary yet

        $r = $this->device($this->cookieFor(Role::ADMIN))->getJson('/api/usage/summary')->assertOk();

        $rows = collect($r->json('data'))->keyBy('name');
        $this->assertSame([2, 1, '10.000000', 200], [$rows['alpha']['requests'], $rows['alpha']['failures'], $rows['alpha']['cost'], $rows['alpha']['avg_latency_ms']]);
        $this->assertSame([1, 1, 1, null, 1000], [$rows['beta']['requests'], $rows['beta']['estimated_requests'], $rows['beta']['unpriced_requests'], $rows['beta']['cost'], $rows['beta']['input_tokens'] + $rows['beta']['output_tokens']]);
        $this->device($this->cookieFor(Role::ADMIN))->getJson('/api/usage/summary?from=2026-12-01&to=2026-11-01')->assertStatus(422);
    }

    public function test_an_employee_sees_only_their_own_allowance_and_no_provider_detail(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['daily_token_limit' => 1000]);
        $p = $this->conn('SecretProviderName', 10);
        $L = app(Ledger::class);
        $L->finish($L->begin($emp, $p, 'r1', 1, 'm1'), 'success', 200, 100);
        [, $cookie] = $this->signIn($emp);

        $r = $this->device($cookie)->getJson('/api/usage/today')->assertOk();

        $this->assertSame([1000, 300, 700, 30], [$r->json('data.limit'), $r->json('data.used'), $r->json('data.remaining'), $r->json('data.percent')]);
        foreach (['SecretProviderName', 'claude', 'provider', 'model', 'cost'] as $leak) {
            $this->assertStringNotContainsString($leak, $r->getContent());
        }
    }

    // ---- extra tokens --------------------------------------------------------------------------

    public function test_an_admin_can_give_an_employee_extra_tokens_for_today_but_not_an_admin(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['daily_token_limit' => 100]);
        $otherAdmin = $this->makeUser(Role::ADMIN);
        [, $admin] = $this->signIn($this->makeUser(Role::ADMIN));

        $r = $this->device($admin)->postJson("/api/users/{$emp->id}/token-grants", ['tokens' => 500, 'note' => 'Big report'])->assertOk();

        $this->assertSame([100, 500, 600], [$r->json('data.limit'), $r->json('data.extra'), $r->json('data.total')]);
        $this->device($admin)->postJson("/api/users/{$otherAdmin->id}/token-grants", ['tokens' => 500])->assertStatus(403);
        $this->device($admin)->postJson("/api/users/{$emp->id}/token-grants", ['tokens' => 0])->assertStatus(422);
        $this->device($admin)->postJson("/api/users/{$emp->id}/token-grants", ['tokens' => 'lots'])->assertStatus(422);
        $this->assertSame(1, \App\Models\TokenGrant::count());
        $this->assertSame(1, AuditEntry::where('action', 'tokens.granted')->where('target_id', $emp->id)->count());
        $this->device($this->cookieFor(Role::EMPLOYEE))->postJson("/api/users/{$emp->id}/token-grants", ['tokens' => 5])->assertStatus(403);
    }

    public function test_health_reflects_provider_readiness(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $get = fn () => collect($this->device($c)->getJson('/api/system/health')->json('data'))->keyBy('key')['providers'];

        $this->assertSame('unknown', $get()['status']);
        $a = $this->conn('a', 10);
        $b = $this->conn('b', 20);
        $this->assertSame('ok', $get()['status']);
        $a->update(['status' => 'reauth_required']);
        $this->assertSame('warn', $get()['status']);
        $b->update(['unavailable_until' => now()->addMinute(), 'status' => 'unavailable']);
        $this->assertSame('fail', $get()['status']);
    }
}
