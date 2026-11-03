<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ExternalService;
use DateTimeInterface;
use RuntimeException;

/**
 * Today's spend cap for a service is used up. Jobs release until `$resumeAt` (docs/05 §7) — not a failure.
 */
class DailyCapReachedException extends RuntimeException
{
    public function __construct(
        public readonly ExternalService $service,
        public readonly float $cap,
        public readonly float $used,
        public readonly DateTimeInterface $resumeAt,
    ) {
        parent::__construct("[{$service->value}] daily cap of {$cap} reached ({$used} used)");
    }
}
