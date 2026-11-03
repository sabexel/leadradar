<?php

declare(strict_types=1);

use App\Enums\ExternalService;
use App\Exceptions\DailyCapReachedException;
use App\Exceptions\RateLimitedException;
use App\Models\ApiUsage;
use App\Models\Setting;
use App\Services\Support\ApiUsageLimiter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->limiter = app(ApiUsageLimiter::class);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('records usage with cost units on the business date', function () {
    CarbonImmutable::setTestNow('2026-11-03 23:30:00 UTC'); // 23:30 GMT, same day in London

    $usage = $this->limiter->record(ExternalService::MillionVerifier, '/api/v3', 200, 312, 1.0);

    expect($usage->service)->toBe(ExternalService::MillionVerifier)
        ->and($usage->endpoint)->toBe('/api/v3')
        ->and($usage->status_code)->toBe(200)
        ->and($usage->duration_ms)->toBe(312)
        ->and($usage->cost_units)->toBe('1.00')
        ->and($usage->date->toDateString())->toBe('2026-11-03')
        ->and($this->limiter->usedToday(ExternalService::MillionVerifier))->toBe(1.0);
});

it('uses the London day, not UTC, for daily caps', function () {
    CarbonImmutable::setTestNow('2026-07-01 23:30:00 UTC'); // 00:30 BST on 2 July

    expect($this->limiter->businessDate())->toBe('2026-07-02');
});

it('allows calls while under the daily cap', function () {
    Setting::put('cap.brave.per_day', 3);
    ApiUsage::factory()->count(2)->create(['service' => ExternalService::Brave, 'date' => $this->limiter->businessDate()]);

    $this->limiter->ensureAvailable(ExternalService::Brave);

    expect($this->limiter->remainingToday(ExternalService::Brave))->toBe(1.0);
});

it('throws when the daily cap is reached', function () {
    Setting::put('cap.millionverifier.per_day', 2);
    ApiUsage::factory()->count(2)->create(['service' => ExternalService::MillionVerifier, 'date' => $this->limiter->businessDate()]);

    $this->limiter->ensureAvailable(ExternalService::MillionVerifier);
})->throws(DailyCapReachedException::class, 'daily cap of 2 reached');

it('only counts today and the same service towards the daily cap', function () {
    Setting::put('cap.brave.per_day', 1);
    ApiUsage::factory()->create(['service' => ExternalService::Brave, 'date' => now()->subDays(1)]);
    ApiUsage::factory()->create(['service' => ExternalService::MillionVerifier, 'date' => $this->limiter->businessDate()]);

    $this->limiter->ensureAvailable(ExternalService::Brave);

    expect($this->limiter->usedToday(ExternalService::Brave))->toBe(0.0);
});

it('tells capped jobs to resume at 06:00 London the next day', function () {
    CarbonImmutable::setTestNow('2026-11-03 14:00:00 UTC');
    Setting::put('cap.brave.per_day', 0);

    try {
        $this->limiter->ensureAvailable(ExternalService::Brave);
        $this->fail('Expected DailyCapReachedException');
    } catch (DailyCapReachedException $e) {
        expect($e->service)->toBe(ExternalService::Brave)
            ->and(CarbonImmutable::instance($e->resumeAt)->setTimezone('Europe/London')->format('Y-m-d H:i'))
            ->toBe('2026-11-04 06:00');
    }
});

it('has no daily cap for services without a cap setting', function () {
    ApiUsage::factory()->count(5)->create(['service' => ExternalService::Reed, 'date' => $this->limiter->businessDate()]);

    $this->limiter->ensureAvailable(ExternalService::Reed);

    expect($this->limiter->dailyCap(ExternalService::Reed))->toBeNull()
        ->and($this->limiter->remainingToday(ExternalService::Reed))->toBeNull();
});

it('enforces the per-minute limit from config', function () {
    config(['services.reed.per_minute' => 2]);

    $this->limiter->record(ExternalService::Reed, '/search', 200, 100);
    $this->limiter->record(ExternalService::Reed, '/search', 200, 100);

    $this->limiter->ensureAvailable(ExternalService::Reed);
})->throws(RateLimitedException::class, 'per-minute limit of 2 reached');

it('enforces the Companies House rolling 5-minute cap', function () {
    Setting::put('cap.companies_house.per_5min', 3);
    config(['services.companies_house.per_minute' => null]);
    ApiUsage::factory()->count(3)->create(['service' => ExternalService::CompaniesHouse, 'created_at' => now()->subMinutes(2)]);

    try {
        $this->limiter->ensureAvailable(ExternalService::CompaniesHouse);
        $this->fail('Expected RateLimitedException');
    } catch (RateLimitedException $e) {
        expect($e->retryAfterSeconds)->toBe(60);
    }

    ApiUsage::query()->update(['created_at' => now()->subMinutes(6)]);

    $this->limiter->ensureAvailable(ExternalService::CompaniesHouse);
});

it('registers a named job rate limiter per service', function (ExternalService $service) {
    expect(RateLimiter::limiter($service->value))->not->toBeNull();
})->with([
    ExternalService::CompaniesHouse,
    ExternalService::Reed,
    ExternalService::Adzuna,
    ExternalService::MillionVerifier,
    ExternalService::Brave,
]);
