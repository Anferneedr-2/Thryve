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
