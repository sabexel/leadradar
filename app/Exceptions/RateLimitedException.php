<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ExternalService;
use RuntimeException;

/**
 * A short-window limit was hit (our per-minute limit, the provider's 429, or a rolling window).
 * Jobs should release themselves for `$retryAfterSeconds`.
 */
class RateLimitedException extends RuntimeException
{
    public function __construct(
        public readonly ExternalService $service,
        public readonly int $retryAfterSeconds,
        string $reason = 'rate limit reached',
    ) {
        parent::__construct("[{$service->value}] {$reason}; retry after {$retryAfterSeconds}s");
    }
}
