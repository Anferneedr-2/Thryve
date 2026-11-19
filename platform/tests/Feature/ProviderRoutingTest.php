<?php

namespace Tests\Feature;

use App\Ai\HermesClient;
use App\Ai\NoProviderAvailable;
use App\Ai\ProviderException;
use App\Ai\ProviderRouter;
use App\Ai\TokenLimitReached;
use App\Models\Notification;
use App\Models\ProviderConnection;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    private ProviderRouter $router;

    private User $user;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->router = app(ProviderRouter::class);
        $this->user = User::factory()->role(Role::EMPLOYEE)->create(['daily_token_limit' => 100000]);
    }

    private function conn(string $name, int $priority, array $attrs = []): ProviderConnection
    {
        return ProviderConnection::create(['name' => $name, 'kind' => 'claude', 'priority' => $priority] + $attrs);
    }

    private function ok(int $in = 10, int $out = 5): \Closure
    {
        return fn () => ['input' => $in, 'output' => $out];
    }

    private function route(\Closure $attempt, ?string $id = null, array $extra = []): array
    {
        return $this->router->run($this->user, $id ?? 'req-'.++$this->n, 'm1', $attempt, ...$extra);
    }

    private function names(): array
    {
        return $this->router->eligible()->pluck('name')->all();
    }

    // ---- who is eligible, and in what order ----------------------------------------------------

    public function test_connections_are_tried_in_priority_order(): void
    {
        $this->conn('third', 30);
        $this->conn('first', 10);
        $this->conn('second', 20);

        $this->assertSame(['first', 'second', 'third'], $this->names());
        $this->assertSame('first', $this->route($this->ok())['connection']->name);
    }

    public function test_disabled_removed_and_unusable_connections_are_skipped(): void
    {
        $this->conn('good', 50);
        $this->conn('disabled', 1, ['is_enabled' => false]);
        $this->conn('removed', 2, ['removed_at' => now()]);
        $this->conn('reauth', 3, ['status' => 'reauth_required']);
        $this->conn('exhausted', 4, ['status' => 'quota_exhausted']);
        $this->conn('quota-spent', 5, ['quota_known' => true, 'quota_limit' => 1000, 'quota_used' => 1000]);

        $this->assertSame(['good'], $this->names());
    }

    public function test_an_unknown_quota_is_never_treated_as_exhausted(): void
    {
        $this->conn('unknown-quota', 1, ['quota_known' => false, 'quota_limit' => 10, 'quota_used' => 999]);
        $this->conn('known-with-room', 2, ['quota_known' => true, 'quota_limit' => 1000, 'quota_used' => 10]);

        $this->assertSame(['unknown-quota', 'known-with-room'], $this->names());
    }

    public function test_a_known_quota_that_has_reset_makes_the_connection_available_again(): void
    {
        $c = $this->conn('capped', 1, ['status' => 'quota_exhausted', 'quota_known' => true, 'quota_limit' => 100, 'quota_used' => 100, 'quota_resets_at' => now()->addHour()]);
        $this->assertSame([], $this->names());

        $this->travelTo(now()->addHours(2));

        $this->assertSame(['capped'], $this->names());
        $this->assertSame(0, (int) $c->fresh()->quota_used);
    }

    // ---- normal success ------------------------------------------------------------------------

    public function test_a_successful_request_is_recorded_once_and_marks_the_connection_available(): void
    {
        $c = $this->conn('only', 1);

        $out = $this->route($this->ok(120, 30), 'req-ok');

        $u = UsageRecord::sole();
        $this->assertSame(['req-ok#1', 'success', 120, 30, false], [$u->attempt_id, $u->status, $u->input_tokens, $u->output_tokens, $u->is_estimate]);
        $this->assertSame($c->id, $u->provider_connection_id);
        $this->assertNotNull($u->latency_ms);
        $this->assertSame('available', $c->fresh()->status);
        $this->assertSame($u->id, $out['usage']->id);
    }

    // ---- failover and recovery -----------------------------------------------------------------

    public function test_a_retryable_failure_moves_to_the_next_connection_and_records_both_attempts(): void
    {
        $admin = User::factory()->role(Role::ADMIN)->create();
        $a = $this->conn('preferred', 1);
        $b = $this->conn('backup', 2);
        $calls = [];

        $out = $this->route(function (ProviderConnection $c) use (&$calls) {
            $calls[] = $c->name;
            if ($c->name === 'preferred') {
                throw new ProviderException('unavailable');
            }

            return ['input' => 50, 'output' => 20];
        }, 'req-failover');

        $this->assertSame(['preferred', 'backup'], $calls);
        $this->assertSame('backup', $out['connection']->name);
        $rows = UsageRecord::orderBy('id')->get();
        $this->assertSame(['req-failover#1', 'req-failover#2'], $rows->pluck('attempt_id')->all());
        $this->assertSame(['failed', 'success'], $rows->pluck('status')->all());
        $this->assertSame([0, 70], $rows->map(fn ($r) => $r->input_tokens + $r->output_tokens)->all(), 'a failure before any work costs nothing');
        $this->assertFalse($rows[0]->is_estimate);
        $this->assertSame('unavailable', $a->fresh()->status);
        $this->assertSame('unavailable', $a->fresh()->last_error);
        $this->assertNotNull($a->fresh()->unavailable_until);
        $this->assertSame('available', $b->fresh()->status);
        $this->assertSame('provider.failure', Notification::where('user_id', $admin->id)->value('type'));
    }

    public function test_the_failed_connection_is_skipped_during_its_cooldown_then_preferred_again(): void
    {
        $a = $this->conn('preferred', 1);
        $this->conn('backup', 2);
        $fail = true;
        $attempt = function (ProviderConnection $c) use (&$fail) {
            if ($c->name === 'preferred' && $fail) {
                throw new ProviderException('timeout');
            }

            return ['input' => 1, 'output' => 1];
        };

        $this->assertSame('backup', $this->route($attempt)['connection']->name);
        $this->assertSame('backup', $this->route($attempt)['connection']->name, 'still cooling down: not even tried');
        $this->assertSame(1, UsageRecord::where('provider_connection_id', $a->id)->count(), 'the preferred connection was only tried once');

        $this->travelTo(now()->addSeconds(61));
        $fail = false;

        $this->assertSame('preferred', $this->route($attempt)['connection']->name, 'back to the preferred connection once it is available');
        $this->assertSame('available', $a->fresh()->status);
        $this->assertNull($a->fresh()->unavailable_until);
    }

    public function test_the_cooldown_length_comes_from_settings(): void
    {
        \App\Models\Setting::create(['key' => 'provider_cooldown_seconds', 'value' => 600]);
        $a = $this->conn('preferred', 1);
        $this->conn('backup', 2);
        $this->route(fn (ProviderConnection $c) => $c->name === 'preferred' ? throw new ProviderException('unavailable') : ['input' => 1, 'output' => 1]);

        $this->travelTo(now()->addSeconds(300));
        $this->assertSame(['backup'], $this->names());
        $this->travelTo(now()->addSeconds(400));
        $this->assertSame(['preferred', 'backup'], $this->names());
    }

    public function test_rejected_credentials_stop_that_connection_and_alert_admins_once(): void
    {
        $admin = User::factory()->role(Role::ADMIN)->create();
        $a = $this->conn('preferred', 1);
        $this->conn('backup', 2);
        $attempt = fn (ProviderConnection $c) => $c->name === 'preferred' ? throw new ProviderException('unauthorized') : ['input' => 1, 'output' => 1];

        $this->route($attempt);
        $this->route($attempt);

        $this->assertSame('reauth_required', $a->fresh()->status);
        $this->assertNull($a->fresh()->unavailable_until);
        $this->assertSame(['backup'], $this->names(), 'excluded until a Super Admin reconnects it');
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'provider.reauth')->count());
    }

    public function test_a_provider_that_reports_its_quota_is_used_up_is_set_aside(): void
    {
        $a = $this->conn('preferred', 1);
        $this->conn('backup', 2);

        $this->route(fn (ProviderConnection $c) => $c->name === 'preferred' ? throw new ProviderException('quota_exhausted') : ['input' => 1, 'output' => 1]);

        $this->assertSame('quota_exhausted', $a->fresh()->status);
        $this->assertSame(['backup'], $this->names());
    }

    public function test_alerts_do_not_repeat_within_the_hour(): void
    {
        $admin = User::factory()->role(Role::ADMIN)->create();
        $a = $this->conn('flaky', 1);
        $this->conn('backup', 2);
        $attempt = fn (ProviderConnection $c) => $c->name === 'flaky' ? throw new ProviderException('unavailable') : ['input' => 1, 'output' => 1];

        $this->route($attempt);
        $this->travelTo(now()->addMinutes(2));
        $this->route($attempt); // fails again after its cooldown: a second failure, same hour

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'provider.failure')->count());
    }

    // ---- never a duplicate charge --------------------------------------------------------------

    public function test_an_ambiguous_failure_is_not_sent_to_a_second_connection(): void
    {
        $this->conn('preferred', 1);
        $this->conn('backup', 2);
        $calls = [];

        try {
            $this->route(function (ProviderConnection $c) use (&$calls) {
                $calls[] = $c->name;
                throw new ProviderException('timeout', retryable: true, ambiguous: true);
            }, 'req-amb', ['estimate' => 400]);
            $this->fail('an ambiguous failure must surface');
        } catch (ProviderException $e) {
            $this->assertTrue($e->ambiguous);
        }

        $this->assertSame(['preferred'], $calls, 'the backup was never asked');
        $r = UsageRecord::sole();
        $this->assertSame(['failed', true, 400], [$r->status, $r->is_estimate, $r->input_tokens + $r->output_tokens], 'the provider may have charged: keep the estimate, flagged');
    }

    public function test_a_failure_that_cannot_succeed_elsewhere_is_not_retried(): void
    {
        $a = $this->conn('preferred', 1);
        $this->conn('backup', 2);
        $calls = 0;

        try {
            $this->route(function () use (&$calls) {
                $calls++;
                throw new ProviderException('bad_request', retryable: false);
            });
            $this->fail('should have surfaced');
        } catch (ProviderException $e) {
            $this->assertSame('bad_request', $e->category);
        }

        $this->assertSame(1, $calls);
        $this->assertSame('unknown', $a->fresh()->status, 'a bad request is not the connection\'s fault');
    }

    public function test_a_failure_that_reports_exact_tokens_records_those(): void
    {
        $this->conn('only', 1);
        try {
            $this->route(fn () => throw new ProviderException('unavailable', retryable: false, inputTokens: 120, outputTokens: 30));
        } catch (ProviderException) {
        }

        $r = UsageRecord::sole();
        $this->assertSame([120, 30, false], [$r->input_tokens, $r->output_tokens, $r->is_estimate]);
    }

    public function test_an_unexpected_error_is_recorded_and_rethrown(): void
    {
        $this->conn('only', 1);

        $this->expectException(\LogicException::class);
        try {
            $this->route(fn () => throw new \LogicException('bug'));
        } finally {
            $this->assertSame(['failed', 'error'], [UsageRecord::sole()->status, UsageRecord::sole()->failure]);
        }
    }

    // ---- nothing available / limits ------------------------------------------------------------

    public function test_when_every_connection_fails_the_caller_gets_one_clear_error(): void
    {
        $this->conn('one', 1);
        $this->conn('two', 2);

        try {
            $this->route(fn () => throw new ProviderException('unavailable'));
            $this->fail('expected NoProviderAvailable');
        } catch (NoProviderAvailable $e) {
            $this->assertSame('unavailable', $e->lastCategory);
            $this->assertStringNotContainsString('one', $e->getMessage(), 'no provider names leak to employees');
        }
        $this->assertSame(2, UsageRecord::count());
        $this->assertSame([], $this->names(), 'both are cooling down');
    }

    public function test_with_no_connections_at_all_nothing_is_attempted(): void
    {
        $called = false;
        try {
            $this->route(function () use (&$called) {
                $called = true;

                return ['input' => 1, 'output' => 1];
            });
            $this->fail('expected NoProviderAvailable');
        } catch (NoProviderAvailable) {
            $this->assertFalse($called);
            $this->assertSame(0, UsageRecord::count());
        }
    }

    public function test_an_exhausted_allowance_stops_the_request_before_any_provider_is_called(): void
    {
        $this->user->forceFill(['daily_token_limit' => 10])->save();
        $this->conn('only', 1);
        $this->route($this->ok(10, 0));
        $called = false;

        try {
            $this->route(function () use (&$called) {
                $called = true;

                return ['input' => 1, 'output' => 1];
            });
            $this->fail('expected TokenLimitReached');
        } catch (TokenLimitReached) {
            $this->assertFalse($called);
            $this->assertSame(1, UsageRecord::count());
        }
    }

    public function test_background_analysis_routes_without_a_person(): void
    {
        User::factory()->role(Role::SUPER_ADMIN)->create();
        $this->conn('only', 1);

        $out = $this->router->run(null, 'analysis-1', 'm1', $this->ok(200, 50), 'analysis');

        $this->assertSame('analysis', $out['usage']->purpose);
        $this->assertSame(0, app(\App\Ai\Allowance::class)->summary($this->user)['used']);
    }

    // ---- Hermes client -------------------------------------------------------------------------

    public function test_hermes_reports_not_configured_without_an_address(): void
    {
        config(['services.hermes.url' => null]);
        Http::fake();

        $this->assertSame(['ok' => false, 'category' => 'not_configured', 'status' => null], app(HermesClient::class)->ping());
        Http::assertNothingSent();
    }

    public function test_hermes_ping_classifies_answers_and_sends_the_key_only_as_a_bearer_token(): void
    {
        config(['services.hermes.url' => 'https://hermes.example.com', 'services.hermes.key' => 'super-secret-key']);
        $cases = [200 => ['ok', true], 404 => ['ok', true], 401 => ['unauthorized', false], 403 => ['unauthorized', false], 429 => ['rate_limited', false], 500 => ['unavailable', false], 503 => ['unavailable', false]];

        $sequence = Http::sequence();
        foreach (array_keys($cases) as $status) {
            $sequence->pushStatus($status);
        }
        Http::fake(['*' => $sequence]);
        foreach ($cases as $status => [$category, $ok]) {
            $r = app(HermesClient::class)->ping();
            $this->assertSame([$ok, $category, $status], [$r['ok'], $r['category'], $r['status']], "HTTP {$status}");
            $this->assertStringNotContainsString('super-secret-key', json_encode($r));
        }
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer super-secret-key') && ! str_contains($req->url(), 'super-secret-key'));
    }

    public function test_hermes_connection_problems_become_categories_without_leaking_the_address(): void
    {
        config(['services.hermes.url' => 'https://secret-host.internal:8443', 'services.hermes.key' => 'k']);

        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('cURL error 28: Connection timed out for https://secret-host.internal:8443')
            ->pushFailedConnection('cURL error 7: Failed to connect to secret-host.internal port 8443')]);
        $timeout = app(HermesClient::class)->ping();
        $down = app(HermesClient::class)->ping();

        $this->assertSame('timeout', $timeout['category']);
        $this->assertSame('unavailable', $down['category']);
        $this->assertStringNotContainsString('secret-host', json_encode([$timeout, $down]));
    }

    public function test_hermes_results_map_to_router_exceptions(): void
    {
        $this->assertNull(HermesClient::toException(['category' => 'ok']));
        $this->assertSame('unauthorized', HermesClient::toException(['category' => 'unauthorized'])->category);
        $this->assertSame('timeout', HermesClient::toException(['category' => 'timeout'])->category);
        $this->assertSame('unavailable', HermesClient::toException(['category' => 'not_configured'])->category);
        $this->assertTrue(HermesClient::toException(['category' => 'rate_limited'])->retryable);
    }
}
