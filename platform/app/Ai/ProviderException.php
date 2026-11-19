<?php

namespace App\Ai;

use RuntimeException;

/**
 * A failed provider call, described without raw provider text. `category` is one of:
 * unauthorized, rate_limited, unavailable, timeout, quota_exhausted, bad_request.
 * `retryable` means another connection may succeed; `ambiguous` means the provider may already have done (and
 * charged for) the work, so the same request must NOT be sent to a second connection.
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable = true,
        public readonly bool $ambiguous = false,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {
        parent::__construct("Provider call failed: {$category}");
    }
}
