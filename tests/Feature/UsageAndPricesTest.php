<?php

namespace Tests\Feature;

use App\Ai\Ledger;
use App\Models\Agent;
use App\Models\AuditEntry;
use App\Models\PriceEntry;
use App\Models\Role;
use App\Models\TokenGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SimulatesDevices;
use Tests\TestCase;

/** Prices, the usage summary, the employee's own allowance, extra tokens and the health row for agents. */
class UsageAndPricesTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['services.hermes.url' => null, 'services.hermes.key' => null]);
    }

    private function agent(string $name, array $attrs = []): Agent
    {
        return Agent::create(['name' => $name, 'slug' => strtolower($name), 'base_url' => 'https://x.example.com', 'profile' => strtolower($name)] + $attrs);
    }

    public function test_admins_can_read_prices_and_usage_but_only_a_super_admin_can_add_a_price(): void
    {
        $body = ['model' => 'm', 'input_per_million' => '1', 'output_per_million' => '2', 'currency' => 'usd'];
        foreach (['/api/prices', '/api/usage/summary'] as $url) {
            $this->device(null)->getJson($url)->assertStatus(401);
            $this->device($this->cookieFor(Role::EMPLOYEE))->getJson($url)->assertStatus(403);
            $this->device($this->cookieFor(Role::ADMIN))->getJson($url)->assertOk();
        }
        $this->device(null)->postJson('/api/prices', $body)->assertStatus(401);
        $this->device($this->cookieFor(Role::EMPLOYEE))->postJson('/api/prices', $body)->assertStatus(403);
        $this->device($this->cookieFor(Role::ADMIN))->postJson('/api/prices', $body)->assertStatus(403);
        $this->device($this->cookieFor(Role::SUPER_ADMIN))->postJson('/api/prices', $body)->assertCreated();
    }

    public function test_prices_are_added_never_edited_and_validated(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $body = ['model' => 'thryve-bot', 'input_per_million' => '3', 'output_per_million' => '15.5', 'currency' => 'usd'];

        $r = $this->device($c)->postJson('/api/prices', $body)->assertCreated();
        $this->assertSame(['3.0000', '15.5000', 'USD', true], [$r->json('data.input_per_million'), $r->json('data.output_per_million'), $r->json('data.currency'), $r->json('data.in_force')]);
        $this->assertArrayNotHasKey('kind', $r->json('data'));

        $this->device($c)->postJson('/api/prices', ['input_per_million' => '4'] + $body)->assertCreated();
        $this->device($c)->postJson('/api/prices', ['effective_from' => now()->addDays(5)->toIso8601String(), 'input_per_million' => '9'] + $body)->assertCreated();
        $rows = $this->device($this->cookieFor(Role::ADMIN))->getJson('/api/prices')->assertOk()->json('data');
        $this->assertCount(3, $rows, 'the old prices are still there');
        $this->assertContains(false, array_column($rows, 'in_force'), 'a future price is listed but not in force yet');

        foreach ([['model' => 'bad model!'], ['input_per_million' => '-1'], ['output_per_million' => 'abc'], ['currency' => 'US'], ['currency' => '123'], ['input_per_million' => '99999999']] as $bad) {
            $this->device($c)->postJson('/api/prices', $bad + $body)->assertStatus(422);
        }
        $this->assertSame(3, PriceEntry::count());
        $this->assertSame(3, AuditEntry::where('action', 'price.added')->count());
    }

    public function test_the_usage_summary_groups_by_agent_and_flags_estimates_and_unpriced_requests(): void
    {
        app(\App\Ai\PriceBook::class)->add('priced', '10.0000', '10.0000', 'USD', now()->subDay(), null);
        [$a, $b] = [$this->agent('Alpha'), $this->agent('Beta')];
        $u = User::factory()->create(['daily_token_limit' => 10_000_000]);
        $L = app(Ledger::class);
        $L->finish($L->begin($u, $a->id, 'r1', 1, 'priced'), 'success', 1_000_000, 0, latencyMs: 100);
        $L->finish($L->begin($u, $a->id, 'r2', 1, 'priced'), 'failed', 0, 0, latencyMs: 300, failure: 'unavailable');
        $L->finish($L->begin($u, $b->id, 'r3', 1, 'unpriced-model'), 'success', 500, 500, true, 50);
        $L->finish($L->begin($u, null, 'r5', 1, 'priced'), 'success', 10, 0); // from before agents existed
        $L->begin($u, $b->id, 'r4', 1, 'priced', estimate: 999); // still running: not in the summary yet

        $r = $this->device($this->cookieFor(Role::ADMIN))->getJson('/api/usage/summary')->assertOk();

        $rows = collect($r->json('data'))->keyBy('name');
        $this->assertSame([2, 1, '10.000000', 200], [$rows['Alpha']['requests'], $rows['Alpha']['failures'], $rows['Alpha']['cost'], $rows['Alpha']['avg_latency_ms']]);
        $this->assertSame([1, 1, 1, null, 1000], [$rows['Beta']['requests'], $rows['Beta']['estimated_requests'], $rows['Beta']['unpriced_requests'], $rows['Beta']['cost'], $rows['Beta']['input_tokens'] + $rows['Beta']['output_tokens']]);
        $this->assertSame(1, $rows['Earlier usage (before agents)']['requests']);
        $this->device($this->cookieFor(Role::ADMIN))->getJson('/api/usage/summary?from=2026-12-01&to=2026-11-01')->assertStatus(422);
    }

    public function test_an_employee_sees_only_their_own_allowance_and_no_agent_detail(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['daily_token_limit' => 1000]);
        $agent = $this->agent('SecretAgentName');
        $L = app(Ledger::class);
        $L->finish($L->begin($emp, $agent->id, 'r1', 1, 'm1'), 'success', 200, 100);
        [, $cookie] = $this->signIn($emp);

        $r = $this->device($cookie)->getJson('/api/usage/today')->assertOk();

        $this->assertSame([1000, 300, 700, 30], [$r->json('data.limit'), $r->json('data.used'), $r->json('data.remaining'), $r->json('data.percent')]);
        foreach (['SecretAgentName', 'agent', 'provider', 'model', 'cost'] as $leak) {
            $this->assertStringNotContainsString($leak, $r->getContent());
        }
    }

    public function test_one_allowance_is_shared_across_every_chatbot(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['daily_token_limit' => 1000]);
        [$a, $b] = [$this->agent('General'), $this->agent('Coach')];
        $L = app(Ledger::class);
        $L->finish($L->begin($emp, $a->id, 'r1', 1, 'general'), 'success', 300, 100);
        $L->finish($L->begin($emp, $b->id, 'r2', 1, 'coach'), 'success', 200, 100);
        [, $cookie] = $this->signIn($emp);

        $this->device($cookie)->getJson('/api/usage/today')->assertJsonPath('data.used', 700)->assertJsonPath('data.remaining', 300);
    }

    public function test_an_admin_can_give_an_employee_extra_tokens_for_today_but_not_an_admin(): void
    {
        $emp = $this->makeUser(Role::EMPLOYEE, ['daily_token_limit' => 100]);
        $otherAdmin = $this->makeUser(Role::ADMIN);
        [, $admin] = $this->signIn($this->makeUser(Role::ADMIN));

        $r = $this->device($admin)->postJson("/api/users/{$emp->id}/token-grants", ['tokens' => 500, 'note' => 'Big report'])->assertOk();

        $this->assertSame([100, 500, 600], [$r->json('data.limit'), $r->json('data.extra'), $r->json('data.total')]);
        $this->device($admin)->postJson("/api/users/{$otherAdmin->id}/token-grants", ['tokens' => 500])->assertStatus(403);
        $this->device($admin)->postJson("/api/users/{$emp->id}/token-grants", ['tokens' => 0])->assertStatus(422);
        $this->assertSame(1, TokenGrant::count());
        $this->assertSame(1, AuditEntry::where('action', 'tokens.granted')->where('target_id', $emp->id)->count());
        $this->device($this->cookieFor(Role::EMPLOYEE))->postJson("/api/users/{$emp->id}/token-grants", ['tokens' => 5])->assertStatus(403);
    }

    public function test_health_reflects_the_last_test_of_each_agent_without_calling_anything(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $get = fn () => collect($this->device($c)->getJson('/api/system/health')->json('data'))->keyBy('key')['agents'];

        $this->assertSame('unknown', $get()['status']);
        $a = $this->agent('A');
        $b = $this->agent('B');
        $this->assertSame('unknown', $get()['status'], 'never tested');
        $a->update(['status' => 'working']);
        $this->assertSame('ok', $get()['status'], 'one passed, one not tested yet');
        $b->update(['status' => 'key_rejected']);
        $this->assertSame('warn', $get()['status']);
        $a->update(['status' => 'unreachable']);
        $this->assertSame('fail', $get()['status']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }
}
