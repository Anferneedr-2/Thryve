<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Hermes (the AI service on the company VPS) from the server only: the browser never sees the address or key.
 *
 * IMPORTANT: the Hermes request contract is not known yet (plan decision D02). This client therefore only does what
 * is safe without it: it checks that the server answers and whether it accepts the access key. Sending prompts and
 * reading streamed answers and token counts is added in P05 once the contract is confirmed.
 */
class HermesClient
{
    public function configured(): bool
    {
        return filled(config('services.hermes.url'));
    }

    /**
     * @return array{ok: bool, category: string, status: int|null}
     *   category: ok | unauthorized | rate_limited | unavailable | timeout | not_configured
     */
    public function ping(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'category' => 'not_configured', 'status' => null];
        }

        try {
            $request = Http::timeout(5)->connectTimeout(3)->withoutRedirecting();
            if ($key = config('services.hermes.key')) {
                $request = $request->withToken($key);
            }
            $status = $request->get(config('services.hermes.url'))->status();
        } catch (ConnectionException $e) {
            // The raw message can contain the address, so only a category leaves this method.
            $timeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');

            return ['ok' => false, 'category' => $timeout ? 'timeout' : 'unavailable', 'status' => null];
        } catch (\Throwable) {
            return ['ok' => false, 'category' => 'unavailable', 'status' => null];
        }

        return match (true) {
            in_array($status, [401, 403], true) => ['ok' => false, 'category' => 'unauthorized', 'status' => $status],
            $status === 429 => ['ok' => false, 'category' => 'rate_limited', 'status' => $status],
            $status >= 500 => ['ok' => false, 'category' => 'unavailable', 'status' => $status],
            default => ['ok' => true, 'category' => 'ok', 'status' => $status], // any other answer means the server is up
        };
    }

    /**
     * Send a conversation and wait for the full answer (non-streaming).
     *
     * Contract observed against a live Hermes gateway: success is a standard chat.completion with real `usage`.
     * A failure inside Hermes (provider rate limit, bad model, ...) still comes back as HTTP 200, with
     * finish_reason "error", zero usage, the error text inside the assistant message, and a `hermes` block flagging it.
     * Raw provider text is only inspected to pick a category; it never reaches an exception message or a log.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{content: string, input: int, output: int, estimate: bool, finish: string|null, model: string|null}
     *
     * @throws ProviderException
     */
    public function chat(array $messages, ?\App\Models\ProviderConnection $connection = null): array
    {
        if (! $this->configured()) {
            throw new ProviderException('unavailable', retryable: true);
        }

        $key = ($connection?->credentials ?: null) ?? config('services.hermes.key');
        $url = rtrim((string) config('services.hermes.url'), '/').'/v1/chat/completions';

        try {
            $request = Http::timeout((int) config('services.hermes.timeout'))->connectTimeout(5)->withoutRedirecting()->acceptJson();
            if ($key) {
                $request = $request->withToken($key);
            }
            $response = $request->post($url, [
                'model' => config('services.hermes.model'),
                'messages' => $messages,
                'stream' => false,
            ]);
        } catch (ConnectionException $e) {
            $text = strtolower($e->getMessage());
            // A read timeout means the request was sent and may still be running: it must not be repeated elsewhere.
            if (str_contains($text, 'timed out') && ! str_contains($text, 'connection timed out after') && ! str_contains($text, 'could not connect')) {
                throw new ProviderException('timeout', retryable: true, ambiguous: true);
            }
            throw new ProviderException('unavailable', retryable: true);
        }

        $status = $response->status();
        if ($status !== 200) {
            throw match (true) {
                in_array($status, [401, 403], true) => new ProviderException('unauthorized', retryable: true),
                $status === 429 => new ProviderException('rate_limited', retryable: true),
                $status >= 500 => new ProviderException('unavailable', retryable: true, ambiguous: true),
                default => new ProviderException('bad_request', retryable: false),
            };
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new ProviderException('bad_response', retryable: true);
        }

        $choice = $body['choices'][0] ?? [];
        $content = (string) ($choice['message']['content'] ?? '');
        $finish = $choice['finish_reason'] ?? null;
        $input = (int) ($body['usage']['prompt_tokens'] ?? 0);
        $output = (int) ($body['usage']['completion_tokens'] ?? 0);

        if (($body['hermes']['failed'] ?? false) === true || $finish === 'error') {
            // Hermes put the provider's error into the answer text. Classify from it, carry tokens only if any were billed.
            $category = self::classifyError(($body['hermes']['error'] ?? '').' '.($body['hermes']['error_code'] ?? '').' '.$content);
            $billed = $input + $output > 0;
            throw new ProviderException(
                $category,
                retryable: $category !== 'bad_request',
                ambiguous: $billed,
                inputTokens: $billed ? $input : 0,
                outputTokens: $billed ? $output : 0,
            );
        }

        if (trim($content) === '') {
            throw new ProviderException('bad_response', retryable: true, ambiguous: $input + $output > 0, inputTokens: $input, outputTokens: $output);
        }

        $estimate = false;
        if ($input + $output === 0) {
            // Usage missing: estimate (about four characters per token) and flag it so reports can show "estimated".
            $estimate = true;
            $input = (int) ceil(strlen(json_encode($messages)) / 4);
            $output = (int) ceil(strlen($content) / 4);
        }

        return [
            'content' => $content,
            'input' => $input,
            'output' => $output,
            'estimate' => $estimate,
            'finish' => $finish,
            'model' => $body['model'] ?? null,
        ];
    }

    /** Map Hermes' error text to a router category. Order matters: the most specific signals first. */
    private static function classifyError(string $text): string
    {
        $t = strtolower($text);

        return match (true) {
            str_contains($t, 'quota') || str_contains($t, 'insufficient') || str_contains($t, 'billing') => 'quota_exhausted',
            str_contains($t, '429') || str_contains($t, 'rate limit') || str_contains($t, 'rate_limit') => 'rate_limited',
            str_contains($t, '401') || str_contains($t, '403') || str_contains($t, 'unauthor') || str_contains($t, 'forbidden') || str_contains($t, 'expired') => 'unauthorized',
            str_contains($t, '400') || str_contains($t, '404') || str_contains($t, 'not found') || str_contains($t, 'invalid') => 'bad_request',
            default => 'unavailable',
        };
    }

    /** Turn a ping result into the exception the router understands. */
    public static function toException(array $ping): ?ProviderException
    {
        return match ($ping['category']) {
            'ok' => null,
            'unauthorized' => new ProviderException('unauthorized', retryable: true),
            'rate_limited' => new ProviderException('rate_limited', retryable: true),
            'timeout' => new ProviderException('timeout', retryable: true),
            default => new ProviderException('unavailable', retryable: true),
        };
    }
}
