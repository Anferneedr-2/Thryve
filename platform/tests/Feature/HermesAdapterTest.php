<?php

namespace Tests\Feature;

use App\Ai\HermesClient;
use App\Ai\ProviderException;
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

    public function test_an_explicit_target_sends_its_own_address_key_and_model(): void
    {
        Http::fake(['*' => Http::response($this->success())]);

        $this->hermes->chat(self::MSG, ['agent_id' => 1, 'url' => 'https://coach.example.com/p/coach', 'key' => 'coach-key-999999', 'model' => 'coach']);

        Http::assertSent(fn ($req) => $req->url() === 'https://coach.example.com/p/coach/v1/chat/completions'
            && $req->hasHeader('Authorization', 'Bearer coach-key-999999') && $req['model'] === 'coach');
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

    public function test_a_redirect_to_a_web_login_is_not_treated_as_a_bad_request_and_nothing_is_charged(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => '/login?next=%2Fv1%2Fchat%2Fcompletions'])]);

        $e = $this->category(fn () => $this->hermes->chat(self::MSG));

        $this->assertSame('unavailable', $e->category);
        $this->assertTrue($e->retryable);
        $this->assertFalse($e->ambiguous);
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

    // ---- ping (used for reachability checks) ----------------------------------------------------

    public function test_hermes_reports_not_configured_without_an_address(): void
    {
        config(['services.hermes.url' => null]);
        Http::fake();

        $this->assertSame(['ok' => false, 'category' => 'not_configured', 'status' => null], $this->hermes->ping());
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
            $r = $this->hermes->ping();
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
        $timeout = $this->hermes->ping();
        $down = $this->hermes->ping();

        $this->assertSame('timeout', $timeout['category']);
        $this->assertSame('unavailable', $down['category']);
        $this->assertStringNotContainsString('secret-host', json_encode([$timeout, $down]));
    }
}
