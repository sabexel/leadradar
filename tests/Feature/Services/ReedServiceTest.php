<?php

declare(strict_types=1);

use App\Enums\ExternalService;
use App\Enums\SignalSource;
use App\Exceptions\DailyCapReachedException;
use App\Exceptions\ExternalServiceException;
use App\Exceptions\RateLimitedException;
use App\Models\ApiUsage;
use App\Models\Setting;
use App\Services\JobBoards\ReedService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    config(['services.reed.key' => 'test-reed-key']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('searches direct-employer ads with basic auth and maps them to JobAdData', function () {
    Http::fake(['www.reed.co.uk/api/1.0/search*' => Http::response(jsonFixture('reed/search.json'))]);

    $page = app(ReedService::class)->search('laravel', 100, 100);

    Http::assertSent(function (Request $request) {
        return str_starts_with($request->url(), 'https://www.reed.co.uk/api/1.0/search?')
            && $request['keywords'] === 'laravel'
            && $request['postedByDirectEmployer'] === 'true'
            && (int) $request['resultsToSkip'] === 100
            && (int) $request['resultsToTake'] === 100
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('test-reed-key:');
    });

    expect($page->totalResults)->toBe(250)
        ->and($page->ads)->toHaveCount(3);

    $ad = $page->ads->first();

    expect($ad->source)->toBe(SignalSource::Reed)
        ->and($ad->externalId)->toBe('50000001')
        ->and($ad->title)->toBe('Laravel Developer')
        ->and($ad->employerName)->toBe('Example Digital Ltd')
        ->and($ad->employerExternalId)->toBe('900001')
        ->and($ad->location)->toBe('Manchester')
        ->and($ad->description)->toBe('We are a small digital agency looking for a Laravel developer to join our team...')
        ->and($ad->url)->toBe('https://www.reed.co.uk/jobs/laravel-developer/50000001')
        ->and($ad->postedAt?->toDateString())->toBe('2026-11-01')
        ->and($ad->raw['jobId'])->toBe(50000001)
        ->and($ad->isWithinDays(30))->toBeTrue()
        ->and($page->ads->last()->isWithinDays(30))->toBeFalse();
});

it('caps resultsToTake at 100', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/search-empty.json'))]);

    $page = app(ReedService::class)->search('laravel', 0, 500);

    Http::assertSent(fn (Request $request) => (int) $request['resultsToTake'] === 100);
    expect($page->isEmpty())->toBeTrue()
        ->and($page->totalResults)->toBe(0);
});

it('fetches one job with full plain-text description and external url', function () {
    Http::fake(['www.reed.co.uk/api/1.0/jobs/50000001' => Http::response(jsonFixture('reed/job.json'))]);

    $ad = app(ReedService::class)->getJob(50000001);

    expect($ad)->not->toBeNull()
        ->and($ad->externalId)->toBe('50000001')
        ->and($ad->externalUrl)->toBe('https://example-digital.test/careers/laravel-developer')
        ->and($ad->postedAt?->toDateString())->toBe('2026-11-01')
        ->and($ad->description)->toBe('We are a small digital agency in Manchester. You will build client projects with Laravel, Inertia & React. 3+ years PHP Laravel');
});

it('returns null for the blank job Reed sends for an unknown id', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/job-blank.json'))]);

    expect(app(ReedService::class)->getJob(1))->toBeNull();
});

it('logs api usage for every call', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/search-empty.json'))]);

    app(ReedService::class)->search('laravel');

    $usage = ApiUsage::sole();

    expect($usage->service)->toBe(ExternalService::Reed)
        ->and($usage->endpoint)->toBe('/search')
        ->and($usage->status_code)->toBe(200)
        ->and($usage->cost_units)->toBe('1.00');
});

it('throws RateLimitedException on 429', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '30'])]);

    try {
        app(ReedService::class)->search('laravel');
        $this->fail('Expected RateLimitedException');
    } catch (RateLimitedException $e) {
        expect($e->retryAfterSeconds)->toBe(30);
    }
});

it('throws ExternalServiceException on server errors after one retry', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response('oops', 503)]);

    expect(fn () => app(ReedService::class)->search('laravel'))
        ->toThrow(ExternalServiceException::class, 'HTTP 503');

    Http::assertSentCount(2); // retry(2) = two attempts in total
});

it('does not retry client errors', function () {
    Http::fake(['*' => Http::response([], 401)]);

    expect(fn () => app(ReedService::class)->search('laravel'))->toThrow(ExternalServiceException::class, 'HTTP 401');

    Http::assertSentCount(1);
});

it('throws on malformed JSON', function () {
    Http::fake(['*' => Http::response('<html>not json</html>', 200)]);

    app(ReedService::class)->search('laravel');
})->throws(ExternalServiceException::class, 'malformed JSON');

it('refuses to call without an API key', function () {
    config(['services.reed.key' => null]);
    Http::fake();

    expect(fn () => app(ReedService::class)->search('laravel'))->toThrow(ExternalServiceException::class, 'not configured');

    Http::assertNothingSent();
});

it('does not call Reed when a daily cap is set and reached', function () {
    Setting::put('cap.reed.per_day', 0);
    Http::fake();

    expect(fn () => app(ReedService::class)->search('laravel'))->toThrow(DailyCapReachedException::class);

    Http::assertNothingSent();
});
