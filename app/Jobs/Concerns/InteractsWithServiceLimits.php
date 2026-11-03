<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Exceptions\DailyCapReachedException;
use App\Exceptions\RateLimitedException;
use Closure;
use DateTimeInterface;

/**
 * Shared queue settings and limit handling for outbound jobs (docs/05).
 *
 * Hitting a cap or rate limit is not a failure: the job releases itself and keeps its place.
 * Releases don't count as exceptions, so `maxExceptions` (not `tries`) bounds real failures,
 * and `retryUntil()` keeps a capped job alive until the next business day.
 */
trait InteractsWithServiceLimits
{
    public int $timeout = 30;

    public int $maxExceptions = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600];

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(3);
    }

    /**
     * Run the job's ONE outbound call. Returns [false, null] when the job was released instead.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return array{0: bool, 1: TResult|null} [called, result]
     */
    protected function withinServiceLimits(Closure $call): array
    {
        try {
            return [true, $call()];
        } catch (DailyCapReachedException $e) {
            $this->release(max(60, (int) now()->diffInSeconds($e->resumeAt)));
        } catch (RateLimitedException $e) {
            $this->release($e->retryAfterSeconds);
        }

        return [false, null];
    }
}
