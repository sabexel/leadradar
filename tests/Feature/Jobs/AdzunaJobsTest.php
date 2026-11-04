<?php

declare(strict_types=1);

use App\Actions\IngestJobAdAction;
use App\Enums\SignalSource;
use App\Jobs\Sources\FetchAdzunaJobsJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\JobBoards\AdzunaService;
use App\Services\Support\DailyTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    config(['services.adzuna.app_id' => 'test-app-id', 'services.adzuna.app_key' => 'test-app-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function runAdzunaPage(FetchAdzunaJobsJob $job): FetchAdzunaJobsJob
{
    $job->withFakeQueueInteractions()->handle(app(AdzunaService::class), app(DailyTarget::class), app(IngestJobAdAction::class));

    return $job;
}

it('ingests fresh ads as signals and queues the next page', function () {
    $run = ScrapeRun::factory()->create();
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search.json'))]); // count = 120

    runAdzunaPage(new FetchAdzunaJobsJob('laravel', 1, $run->id));

    expect(Signal::where('source', SignalSource::Adzuna)->pluck('external_id')->sort()->values()->all())
        ->toBe(['4900000001', '4900000002'])
        ->and($run->refresh()->signals_found)->toBe(2);

    Queue::assertPushed(FetchAdzunaJobsJob::class, fn ($job) => $job->page === 2 && $job->scrapeRunId === $run->id);
});

it('is idempotent when the same page runs twice', function () {
    $run = ScrapeRun::factory()->create();
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search.json'))]);

    runAdzunaPage(new FetchAdzunaJobsJob('laravel', 1, $run->id));
    runAdzunaPage(new FetchAdzunaJobsJob('laravel', 1, $run->id));

    expect(Signal::count())->toBe(2)
        ->and($run->refresh()->signals_found)->toBe(2);
});

it('skips ads older than signal.max_age_days and passes the limit to Adzuna', function () {
    Setting::put('signal.max_age_days', 5);
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search.json'))]);

    runAdzunaPage(new FetchAdzunaJobsJob('laravel'));

    Http::assertSent(fn ($request) => (int) $request['max_days_old'] === 5);
    expect(Signal::pluck('external_id')->all())->toBe(['4900000001']);
});

it('does not queue the next page when results are empty', function () {
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search-empty.json'))]);

    runAdzunaPage(new FetchAdzunaJobsJob('laravel'));

    Queue::assertNothingPushed();
});

it('stops after the last page of count', function () {
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search.json'))]); // 120 → pages 1..3

    runAdzunaPage(new FetchAdzunaJobsJob('laravel', 3));

    Queue::assertNotPushed(FetchAdzunaJobsJob::class);
});

it('stops at the max_pages safety limit', function () {
    config(['services.adzuna.max_pages' => 2]);
    Http::fake(['*' => Http::response(jsonFixture('adzuna/search.json'))]);

    runAdzunaPage(new FetchAdzunaJobsJob('laravel', 2));

    Queue::assertNotPushed(FetchAdzunaJobsJob::class);
});

it('makes no call once the daily qualified target is reached', function () {
    Setting::put('daily_qualified_target', 1);
    Company::factory()->qualified()->create();
    Http::fake();

    runAdzunaPage(new FetchAdzunaJobsJob('laravel'));

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('releases until 06:00 London tomorrow when the Adzuna cap is used up', function () {
    Setting::put('cap.adzuna.per_day', 0);
    Http::fake();

    $job = runAdzunaPage(new FetchAdzunaJobsJob('laravel'));

    $job->assertReleased(21 * 3600);
    expect(Signal::count())->toBe(0);
});

it('runs on the sources queue with a unique id', function () {
    $job = new FetchAdzunaJobsJob('laravel', 4, 9);

    expect($job->queue)->toBe('sources')
        ->and($job->uniqueId())->toBe('adzuna-search:laravel:4:9')
        ->and($job->maxExceptions)->toBe(3);
});
