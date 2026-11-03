<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ExternalService;
use RuntimeException;
use Throwable;

/**
 * An external service failed (5xx, timeout, malformed response). Jobs may retry.
 */
class ExternalServiceException extends RuntimeException
{
    public function __construct(
        public readonly ExternalService $service,
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct("[{$service->value}] {$message}", 0, $previous);
    }
}
