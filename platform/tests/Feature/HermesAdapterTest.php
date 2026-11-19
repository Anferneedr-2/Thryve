<?php

namespace Tests\Feature;

use App\Ai\HermesClient;
use App\Ai\NoProviderAvailable;
use App\Ai\ProviderException;
use App\Ai\ProviderRouter;
use App\Models\ProviderConnection;
use App\Models\Role;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Hermes adapter against response shapes captured from a live gateway (success and in-band failure). */
class HermesAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://hermes.test/p/thryve-bot';

    private HermesClient $hermes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        config(['services.hermes.url' => self::URL, 'services.hermes.key' => 'server-key-123456', 'services.hermes.model' => 'thryve-bot']);
        $this->hermes = new HermesClient;
    }

    private const MSG = [['role' => 'user', 'content' => 'ping']];

    private function success(int $in = 11271, int $out = 5, string $content = 'pong'): array
    {
        return [
            'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'thryve-bot',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
        ];
    }

    /** Hermes reports its own failures as a normal 200 with the error text inside the answer. */
    private function inBandFailure(string $error): array
    {
        return [
            'id' => 'chatcmpl-2', 'object' => 'chat.completion', 'model' => 'thryve-bot',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => "API call failed: {$error}"], 'finish_reason' => 'error']],
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0],
            'hermes' => ['completed' => false, 'partial' => false, 'failed' => true, 'error' => $error, 'error_code' => null],
        ];
    }

    private function category(callable $fn): ProviderException
    {
        try {
            $fn();
        } catch (ProviderException $e) {
            return $e;
        }
        $this->fail('Expected a ProviderException');
    }

    public function test_success_returns_answer_and_real_token_counts(): void
    {
        Http::fake([self::URL.'/v1/chat/completions' => Http::response($this->success())]);

        $r = $this->hermes->chat(self::MSG);

        $this->assertSame('pong', $r['content']);
        $this->assertSame(11271, $r['input']);
        $this->assertSame(5, $r['output']);
        $this->assertFalse($r['estimate']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer server-key-123456')
            && $req['model'] === 'thryve-bot' && $req['stream'] === false && $req['messages'] === self::MSG);
    }

    public function test_connection_credentials_override_the_server_key(): void
    {
        Http::fake(['*' => Http::response($this->success())]);
        $c = ProviderConnection::create(['name' => 'vps', 'kind' => 'hermes', 'priority' => 1, 'credentials' => 'connection-key-999999']);

        $this->hermes->chat(self::MSG, $c);

        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer connection-key-999999'));
    }

    public function test_missing_usage_is_estimated_and_flagged(): void
    {
        $body = $this->success(0, 0, 'twelve chars');
        unset($body['usage']);
        Http::fake(['*' => Http::response($body)]);

        $r = $this->hermes->chat(self::MSG);

        $this->assertTrue($r['estimate']);
        $this->assertSame(3, $r['output']);
        $this->assertGreaterThan(0, $r['input']);
    }

    public function test_not_configured_is_unavailable(): void
    {
        config(['services.hermes.url' => null]);
        Http::fake();

        $this->assertSame('unavailable', $this->category(fn () => $this->hermes->chat(self::MSG))->category);
        Http::assertNothingSent();
    }

    public static function inBandCases(): array
    {
        return [
            'rate limit' => ['HTTP 429: Too Many Requests', 'rate_limited', true],
            'expired login' => ['HTTP 401: token expired', 'unauthorized', true],
            'unknown model' => ['HTTP 404: model not found', 'bad_request', false],
            'quota' => ['You exceeded your current quota', 'quota_exhausted', true],
            'overloaded' => ['upstream overloaded', 'unavailable', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inBandCases')]
    public function test_failures_reported_inside_a_200_are_classified(string $error, string $category, bool $retryable): void
    {
        Http::fake(['*' => Http::response($this->inBandFailure($error))]);

        $e = $this->category(fn () => $this->hermes->chat(self::MSG));

        $this->assertSame($category, $e->category);
        $this->assertSame($retryable, $e->retryable);
        $this->assertFalse($e->ambiguous);
        $this->assertSame(0, $e->inputTokens);
        $this->assertStringNotContainsString($error, $e->getMessage());
    }

    public function test_in_band_failure_that_billed_tokens_is_ambiguous_and_keeps_the_numbers(): void
    {
        $body = $this->inBandFailure('HTTP 500');
        $body['usage'] = ['prompt_tokens' => 900, 'completion_tokens' => 40, 'total_tokens' => 940];
        Http::fake(['*' => Http::response($body)]);

        $e = $this->category(fn () => $this->hermes->chat(self::MSG));

        $this->assertTrue($e->ambiguous);
        $this->assertSame([900, 40], [$e->inputTokens, $e->outputTokens]);
    }

    public function test_empty_answer_is_a_bad_response(): void
    {
        Http::fake(['*' => Http::response($this->success(10, 0, '  '))]);

        $this->assertSame('bad_response', $this->category(fn () => $this->hermes->chat(self::MSG))->category);
    }

    public function test_non_json_body_is_a_bad_response(): void
    {
        Http::fake(['*' => Http::response('<html>proxy error</html>', 200)]);

        $this->assertSame('bad_response', $this->category(fn () => $this->hermes->chat(self::MSG))->category);
    }

    public static function statusCases(): array
    {
        return [[401, 'unauthorized', false], [403, 'unauthorized', false], [429, 'rate_limited', false], [502, 'unavailable', true], [422, 'bad_request', false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusCases')]
    public function test_http_errors_map_to_categories(int $status, string $category, bool $ambiguous): void
    {
        Http::fake(['*' => Http::response(['error' => 'secret provider text'], $status)]);

        $e = $this->category(fn () => $this->hermes->chat(self::MSG));

        $this->assertSame($category, $e->category);
        $this->assertSame($ambiguous, $e->ambiguous);
        $this->assertStringNotContainsString('secret', $e->getMessage());
    }

    public static function connectionErrors(): array
    {
        return [
            'read timeout' => ['cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received', 'timeout', true],
            'connect refused' => ['cURL error 7: Failed to connect to hermes.test port 80: Connection refused', 'unavailable', false],
            'connect timeout' => ['cURL error 28: Connection timed out after 5001 milliseconds', 'unavailable', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('connectionErrors')]
    public function test_connection_errors_are_classified(string $message, string $category, bool $ambiguous): void
    {
        Http::fake(fn () => throw new ConnectionException($message));

        $e = $this->category(fn () => $this->hermes->chat(self::MSG));

        $this->assertSame($category, $e->category);
        $this->assertSame($ambiguous, $e->ambiguous);
        $this->assertStringNotContainsString('hermes.test', $e->getMessage());
    }

    // ---- through the router and ledger ------------------------------------------------------------

    private function route(User $user, string $id = 'r1'): array
    {
        return app(ProviderRouter::class)->run($user, $id, 'thryve-bot', fn ($c) => $this->hermes->chat(self::MSG, $c));
    }

    private function employee(): User
    {
        return User::factory()->role(Role::EMPLOYEE)->create(['daily_token_limit' => 1000000]);
    }

    public function test_router_records_real_usage_for_a_successful_call(): void
    {
        ProviderConnection::create(['name' => 'vps', 'kind' => 'hermes', 'priority' => 1]);
        Http::fake(['*' => Http::response($this->success(11271, 5))]);
        $user = $this->employee();

        $out = $this->route($user);

        $this->assertSame('pong', $out['result']['content']);
        $row = UsageRecord::where('request_id', 'r1')->sole();
        $this->assertSame('success', $row->status);
        $this->assertSame([11271, 5], [$row->input_tokens, $row->output_tokens]);
        $this->assertFalse((bool) $row->is_estimate);
    }

    public function test_rate_limit_fails_over_to_the_next_connection(): void
    {
        ProviderConnection::create(['name' => 'a', 'kind' => 'hermes', 'priority' => 1]);
        ProviderConnection::create(['name' => 'b', 'kind' => 'hermes', 'priority' => 2]);
        Http::fake(['*' => Http::sequence()->push($this->inBandFailure('HTTP 429'))->push($this->success())]);
        $user = $this->employee();

        $out = $this->route($user);

        $this->assertSame('b', $out['connection']->name);
        $this->assertSame(['failed', 'success'], UsageRecord::where('request_id', 'r1')->orderBy('id')->pluck('status')->all());
    }

    public function test_a_timeout_never_goes_to_a_second_connection(): void
    {
        ProviderConnection::create(['name' => 'a', 'kind' => 'hermes', 'priority' => 1]);
        ProviderConnection::create(['name' => 'b', 'kind' => 'hermes', 'priority' => 2]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds'));
        $user = $this->employee();

        $e = $this->category(fn () => $this->route($user));

        $this->assertSame('timeout', $e->category);
        $this->assertSame(1, UsageRecord::where('request_id', 'r1')->count());
    }

    public function test_one_bad_response_does_not_take_the_connection_out_of_service(): void
    {
        $c = ProviderConnection::create(['name' => 'a', 'kind' => 'hermes', 'priority' => 1, 'status' => 'available']);
        Http::fake(['*' => Http::response('nonsense', 200)]);
        $user = $this->employee();

        try {
            $this->route($user);
        } catch (NoProviderAvailable) {
            // the only connection gave one odd reply; the router reports it, but must not mark the connection down
        }

        $this->assertSame('available', $c->fresh()->status);
    }

    public function test_no_connection_means_no_provider_available(): void
    {
        $this->expectException(NoProviderAvailable::class);
        $this->route($this->employee());
    }
}
