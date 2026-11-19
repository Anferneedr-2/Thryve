<?php

namespace Tests\Feature;

use App\Ai\AgentRunner;
use App\Ai\ProviderException;
use App\Ai\TokenLimitReached;
use App\Models\Agent;
use App\Models\Notification;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One request to one agent: recorded exactly once, never repeated, admins told about problems they can fix. */
class AgentRunnerTest extends TestCase
{
    use RefreshDatabase;

    private AgentRunner $runner;

    private User $user;

    private Agent $agent;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->runner = app(AgentRunner::class);
        $this->user = User::factory()->role(Role::EMPLOYEE)->create(['daily_token_limit' => 100000]);
        $this->agent = Agent::create(['name' => 'General', 'slug' => 'general', 'base_url' => 'https://x.example.com', 'profile' => 'general']);
    }

    private function go(\Closure $attempt, ?Agent $agent = null, array $extra = []): array
    {
        return $this->runner->run($this->user, $agent ?? $this->agent, 'req-'.++$this->n, 'm1', $attempt, ...$extra);
    }

    private function thrower(string $category, bool $ambiguous = false, ?int $in = null, ?int $out = null): \Closure
    {
        return fn () => throw new ProviderException($category, retryable: true, ambiguous: $ambiguous, inputTokens: $in, outputTokens: $out);
    }

    public function test_a_successful_request_is_recorded_with_its_agent_and_real_numbers(): void
    {
        $out = $this->go(fn () => ['input' => 120, 'output' => 30, 'content' => 'hi']);

        $this->assertSame('hi', $out['result']['content']);
        $row = UsageRecord::sole();
        $this->assertSame([$this->agent->id, 'success', 120, 30, false, 'chat'], [$row->agent_id, $row->status, $row->input_tokens, $row->output_tokens, (bool) $row->is_estimate, $row->purpose]);
        $this->assertNull($row->provider_connection_id);
    }

    public function test_a_failure_is_recorded_once_with_zero_tokens_and_rethrown_not_repeated(): void
    {
        $calls = 0;
        $attempt = function () use (&$calls) {
            $calls++;
            throw new ProviderException('unavailable', retryable: true);
        };

        try {
            $this->go($attempt);
            $this->fail('expected an exception');
        } catch (ProviderException $e) {
            $this->assertSame('unavailable', $e->category);
        }

        $this->assertSame(1, $calls, 'there is no failover and no retry');
        $row = UsageRecord::sole();
        $this->assertSame(['failed', 0, 0, 'unavailable'], [$row->status, $row->input_tokens, $row->output_tokens, $row->failure]);
    }

    public function test_an_outcome_nobody_knows_keeps_the_reserved_estimate_flagged(): void
    {
        try {
            $this->go($this->thrower('timeout', ambiguous: true), extra: ['chat', 400]);
        } catch (ProviderException) {
        }

        $row = UsageRecord::sole();
        $this->assertSame(['failed', 400, true], [$row->status, $row->input_tokens, (bool) $row->is_estimate], 'may have been charged: counted, never hidden');
    }

    public function test_tokens_the_agent_reports_on_a_failure_are_recorded(): void
    {
        try {
            $this->go($this->thrower('unavailable', true, 900, 40));
        } catch (ProviderException) {
        }

        $row = UsageRecord::sole();
        $this->assertSame([900, 40, false], [$row->input_tokens, $row->output_tokens, (bool) $row->is_estimate]);
    }

    public function test_an_unexpected_error_closes_the_record_so_it_is_not_left_pending(): void
    {
        try {
            $this->go(fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }

        $this->assertSame(['failed', 'error'], [UsageRecord::sole()->status, UsageRecord::sole()->failure]);
        $this->assertSame(0, UsageRecord::where('status', 'pending')->count());
    }

    public function test_an_exhausted_allowance_stops_before_the_agent_is_called(): void
    {
        $this->user->forceFill(['daily_token_limit' => 10])->save();
        $this->go(fn () => ['input' => 50, 'output' => 0]);
        $called = false;

        try {
            $this->go(function () use (&$called) {
                $called = true;

                return ['input' => 1, 'output' => 1];
            });
            $this->fail('expected the limit to stop it');
        } catch (TokenLimitReached) {
        }

        $this->assertFalse($called);
        $this->assertSame(1, UsageRecord::count());
    }

    public function test_background_work_is_not_charged_to_a_person(): void
    {
        User::factory()->role(Role::SUPER_ADMIN)->create();

        $out = $this->runner->run(null, $this->agent, 'bg-1', 'm1', fn () => ['input' => 500, 'output' => 5], 'analysis');

        $this->assertSame('analysis', $out['usage']->purpose);
        $this->assertSame(0, app(\App\Ai\Allowance::class)->summary($this->user)['used']);
    }

    public function test_admins_hear_about_a_rejected_key_once_per_hour(): void
    {
        $admin = User::factory()->role(Role::SUPER_ADMIN)->create();
        foreach (range(1, 3) as $_) {
            try {
                $this->go($this->thrower('unauthorized'));
            } catch (ProviderException) {
            }
        }

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'provider.reauth')->count());
        $this->assertStringContainsString('General', Notification::where('type', 'provider.reauth')->value('title'));
    }

    public function test_an_unreachable_agent_is_reported_per_agent_and_a_bad_request_is_not(): void
    {
        $admin = User::factory()->role(Role::ADMIN)->create();
        $other = Agent::create(['name' => 'Coach', 'slug' => 'coach', 'base_url' => 'https://y.example.com']);

        foreach ([$this->agent, $this->agent, $other] as $a) {
            try {
                $this->go($this->thrower('unavailable'), $a);
            } catch (ProviderException) {
            }
        }
        try {
            $this->go(fn () => throw new ProviderException('bad_request', retryable: false));
        } catch (ProviderException) {
        }

        $this->assertSame(2, Notification::where('user_id', $admin->id)->where('type', 'provider.failure')->count(), 'one per agent');
        $this->assertSame(2, Notification::where('user_id', $admin->id)->count(), 'a bad request alerts nobody');
    }
}
