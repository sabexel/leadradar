<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\ExternalService;
use App\Exceptions\DailyCapReachedException;
use App\Exceptions\RateLimitedException;
use App\Models\ApiUsage;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Guards spend and rate for every external service.
 *
 * Services call `ensureAvailable()` before an outbound call and `record()` after it (docs/04).
 * - Per-minute limit: `config('services.{service}.per_minute')`, enforced with the RateLimiter.
 * - Rolling 5-minute cap: setting `cap.{service}.per_5min` (Companies House).
 * - Daily cap: setting `cap.{service}.per_day`, summed from `api_usages.cost_units` for the business day.
 */
final class ApiUsageLimiter
{
    /**
     * @throws RateLimitedException
     * @throws DailyCapReachedException
     */
    public function ensureAvailable(ExternalService $service, float $cost = 1.0): void
    {
        $this->ensureWithinPerMinuteLimit($service);
        $this->ensureWithinRollingWindow($service);
        $this->ensureWithinDailyCap($service, $cost);
    }

    public function record(
        ExternalService $service,
        string $endpoint,
        ?int $statusCode,
        ?int $durationMs,
        float $cost = 1.0,
    ): ApiUsage {
        if ($this->perMinuteLimit($service) !== null) {
            RateLimiter::hit($this->minuteKey($service), 60);
        }

        return ApiUsage::create([
            'service' => $service,
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'cost_units' => $cost,
            'date' => $this->businessDate(),
        ]);
    }

    public function usedToday(ExternalService $service): float
    {
        return (float) ApiUsage::query()
            ->where('service', $service)
            ->where('date', $this->businessDate())
            ->sum('cost_units');
    }

    public function dailyCap(ExternalService $service): ?float
    {
        $cap = Setting::get("cap.{$service->value}.per_day");

        return is_numeric($cap) ? (float) $cap : null;
    }

    public function remainingToday(ExternalService $service): ?float
    {
        $cap = $this->dailyCap($service);

        return $cap === null ? null : max(0.0, $cap - $this->usedToday($service));
    }

    /**
     * When jobs blocked by a daily cap should try again: the next business day at `caps_resume_at`.
     */
    public function resumeAt(): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('leadradar.caps_resume_at', '06:00')));

        return CarbonImmutable::now($this->timezone())->addDay()->setTime($hour, $minute);
    }

    public function businessDate(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    private function ensureWithinPerMinuteLimit(ExternalService $service): void
    {
        $limit = $this->perMinuteLimit($service);

        if ($limit !== null && RateLimiter::tooManyAttempts($this->minuteKey($service), $limit)) {
            throw new RateLimitedException(
                $service,
                max(1, RateLimiter::availableIn($this->minuteKey($service))),
                "per-minute limit of {$limit} reached",
            );
        }
    }

    private function ensureWithinRollingWindow(ExternalService $service): void
    {
        $cap = Setting::get("cap.{$service->value}.per_5min");

        if (! is_numeric($cap)) {
            return;
        }

        $used = ApiUsage::query()
            ->where('service', $service)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();

        if ($used >= (int) $cap) {
            throw new RateLimitedException($service, 60, "5-minute cap of {$cap} reached");
        }
    }

    private function ensureWithinDailyCap(ExternalService $service, float $cost): void
    {
        $cap = $this->dailyCap($service);

        if ($cap === null) {
            return;
        }

        $used = $this->usedToday($service);

        if ($used + $cost > $cap) {
            throw new DailyCapReachedException($service, $cap, $used, $this->resumeAt());
        }
    }

    private function perMinuteLimit(ExternalService $service): ?int
    {
        $limit = config("services.{$service->value}.per_minute");

        return is_numeric($limit) ? (int) $limit : null;
    }

    private function minuteKey(ExternalService $service): string
    {
        return "api-usage:{$service->value}:minute";
    }

    private function timezone(): string
    {
        return (string) config('leadradar.timezone', 'Europe/London');
    }
}
