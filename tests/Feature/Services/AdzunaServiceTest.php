<?php

declare(strict_types=1);

use App\Enums\ExternalService;
use App\Enums\SignalSource;
use App\Exceptions\DailyCapReachedException;
use App\Exceptions\ExternalServiceException;
use App\Exceptions\RateLimitedException;
use App\Models\ApiUsage;
use App\Models\Setting;
use App\Services\JobBoards\AdzunaService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    config(['services.adzuna.app_id' => 'test-app-id', 'services.adzuna.app_key' => 'test-app-key']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('searches the GB endpoint newest-first with app credentials and maps ads', function () {
    Http::fake(['api.adzuna.com/v1/api/jobs/gb/search/2*' => Http::response(jsonFixture('adzuna/search.json'))]);

    $page = app(AdzunaService::class)->search('laravel', 2, 30);

    Http::assertSent(function (Request $request) {
        return str_starts_with($request->url(), 'https://api.adzuna.com/v1/api/jobs/gb/search/2?')
            && $request['app_id'] === 'test-app-id'
            && $request['app_key'] === 'test-app-key'
            && $request['what'] === 'laravel'
            && (int) $request['results_per_page'] === 50
            && (int) $request['max_days_old'] === 30
            && $request['sort_by'] === 'date';
    });

    expect($page->totalResults)->toBe(120)
        ->and($page->ads)->toHaveCount(2); // the ad without a company is dropped

    $ad = $page->ads->first();

    expect($ad->source)->toBe(SignalSource::Adzuna)
        ->and($ad->externalId)->toBe('4900000001')
        ->and($ad->title)->toBe('Laravel Developer')
        ->and($ad->employerName)->toBe('Example Pixel Studio Ltd')
        ->and($ad->location)->toBe('Brighton, East Sussex')
        ->and($ad->description)->toBe('Example Pixel Studio Ltd is a small agency building client platforms with Laravel & Vue...')
        ->and($ad->url)->toBe('https://www.adzuna.co.uk/jobs/land/ad/4900000001?se=example')
        ->and($ad->postedAt?->toIso8601ZuluString())->toBe('2026-11-02T08:15:00Z')
        ->and($ad->raw['category']['tag'])->toBe('it-jobs');
});

it('caps results_per_page at 50 and treats an empty page as empty', function () {
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search-empty.json'))]);

    $page = app(AdzunaService::class)->search('laravel', 1, 30, 200);

    Http::assertSent(fn (Request $request) => (int) $request['results_per_page'] === 50);
    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMoreAfter(1, 50))->toBeFalse();
});

it('logs api usage without credentials in the endpoint', function () {
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search-empty.json'))]);

    app(AdzunaService::class)->search('laravel', 3);

    $usage = ApiUsage::sole();

    expect($usage->service)->toBe(ExternalService::Adzuna)
        ->and($usage->endpoint)->toBe('/jobs/gb/search/{page}');
});

it('redacts app_key from connection error messages', function () {
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: timed out for https://api.adzuna.com/v1/api/jobs/gb/search/1?app_id=test-app-id&app_key=test-app-key&what=laravel'
    ));

    try {
        app(AdzunaService::class)->search('laravel');
        $this->fail('Expected ExternalServiceException');
    } catch (ExternalServiceException $e) {
        expect($e->getMessage())->not->toContain('test-app-key')
            ->and($e->getMessage())->not->toContain('test-app-id')
            ->and($e->getMessage())->toContain('app_key=[redacted]')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('throws RateLimitedException on 429', function () {
    Http::fake(['*' => Http::response([], 429)]);

    app(AdzunaService::class)->search('laravel');
})->throws(RateLimitedException::class);

it('refuses to call without credentials', function () {
    config(['services.adzuna.app_key' => '']);
    Http::fake();

    expect(fn () => app(AdzunaService::class)->search('laravel'))->toThrow(ExternalServiceException::class, 'not configured');

    Http::assertNothingSent();
});

it('stops calling once the Adzuna daily cap is used', function () {
    Setting::put('cap.adzuna.per_day', 1);
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search-empty.json'))]);

    app(AdzunaService::class)->search('laravel');

    expect(fn () => app(AdzunaService::class)->search('laravel'))->toThrow(DailyCapReachedException::class);

    Http::assertSentCount(1);
});
