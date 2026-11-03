<?php

declare(strict_types=1);

use App\Actions\IngestJobAdAction;
use App\Enums\SignalSource;
use App\Jobs\Sources\FetchReedJobDetailJob;
use App\Jobs\Sources\FetchReedJobsJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\JobBoards\ReedService;
use App\Services\Support\DailyTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-03 09:00:00');
    config(['services.reed.key' => 'test-reed-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function runSearchPage(FetchReedJobsJob $job): FetchReedJobsJob
{
    $job->withFakeQueueInteractions()->handle(app(ReedService::class), app(DailyTarget::class));

    return $job;
}

function runDetail(FetchReedJobDetailJob $job): FetchReedJobDetailJob
{
    $job->withFakeQueueInteractions()->handle(app(ReedService::class), app(IngestJobAdAction::class));

    return $job;
}

it('queues a detail job per fresh ad and the next page', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/search.json'))]);

    runSearchPage(new FetchReedJobsJob('laravel', 1, 7));

    Queue::assertPushed(FetchReedJobDetailJob::class, 2);
    Queue::assertPushed(FetchReedJobDetailJob::class, fn ($job) => $job->jobId === 50000001 && $job->scrapeRunId === 7);
    Queue::assertPushed(FetchReedJobDetailJob::class, fn ($job) => $job->jobId === 50000002);
    Queue::assertNotPushed(FetchReedJobDetailJob::class, fn ($job) => $job->jobId === 50000003); // older than 30 days
    Queue::assertPushed(FetchReedJobsJob::class, fn ($job) => $job->page === 2 && $job->keywords === 'laravel' && $job->scrapeRunId === 7);
});

it('does not queue the next page when results are empty', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/search-empty.json'))]);

    runSearchPage(new FetchReedJobsJob('laravel'));

    Queue::assertNothingPushed();
});

it('does not queue the next page when every ad is older than signal.max_age_days', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/search-old.json'))]);

    runSearchPage(new FetchReedJobsJob('laravel'));

    Queue::assertNothingPushed();
});

it('respects a changed signal.max_age_days setting', function () {
    Setting::put('signal.max_age_days', 200);
    Http::fake(['*' => Http::response(jsonFixture('reed/search-old.json'))]);

    runSearchPage(new FetchReedJobsJob('laravel'));

    Queue::assertPushed(FetchReedJobDetailJob::class, 1);
    Queue::assertPushed(FetchReedJobsJob::class, 1);
});

it('stops after the last page of totalResults', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/search.json'))]); // totalResults = 250

    runSearchPage(new FetchReedJobsJob('laravel', 3));

    Http::assertSent(fn ($request) => (int) $request['resultsToSkip'] === 200);
    Queue::assertNotPushed(FetchReedJobsJob::class);
});

it('stops at the max_pages safety limit', function () {
    config(['services.reed.max_pages' => 2]);
    Http::fake(['*' => Http::response(jsonFixture('reed/search.json'))]);

    runSearchPage(new FetchReedJobsJob('laravel', 2));

    Queue::assertNotPushed(FetchReedJobsJob::class);
});

it('skips ads already stored as signals', function () {
    Signal::factory()->create(['source' => SignalSource::Reed, 'external_id' => '50000001']);
    Http::fake(['*' => Http::response(jsonFixture('reed/search.json'))]);

    runSearchPage(new FetchReedJobsJob('laravel'));

    Queue::assertPushed(FetchReedJobDetailJob::class, 1);
    Queue::assertPushed(FetchReedJobDetailJob::class, fn ($job) => $job->jobId === 50000002);
});

it('makes no call once the daily qualified target is reached', function () {
    Setting::put('daily_qualified_target', 2);
    Company::factory()->count(2)->qualified()->create();
    Http::fake();

    runSearchPage(new FetchReedJobsJob('laravel'));

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('releases instead of failing when Reed rate-limits', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '45'])]);

    $job = runSearchPage(new FetchReedJobsJob('laravel'));

    $job->assertReleased(45);
    Queue::assertNothingPushed();
});

it('releases until 06:00 London tomorrow when the daily cap is reached', function () {
    Setting::put('cap.reed.per_day', 0);
    Http::fake();

    $job = runSearchPage(new FetchReedJobsJob('laravel'));

    // 09:00 GMT today → 06:00 GMT tomorrow = 21 hours.
    $job->assertReleased(21 * 3600);
    Http::assertNothingSent();
});

it('ingests a job detail as a signal and counts it on the run', function () {
    $run = ScrapeRun::factory()->create();
    Http::fake(['www.reed.co.uk/api/1.0/jobs/50000001' => Http::response(jsonFixture('reed/job.json'))]);

    runDetail(new FetchReedJobDetailJob(50000001, $run->id));
    runDetail(new FetchReedJobDetailJob(50000001, $run->id)); // idempotent

    $signal = Signal::sole();

    expect($signal->source)->toBe(SignalSource::Reed)
        ->and($signal->external_id)->toBe('50000001')
        ->and($signal->employer_name)->toBe('Example Digital Ltd')
        ->and($signal->posted_at?->toDateString())->toBe('2026-11-01')
        ->and($signal->raw['externalUrl'])->toBe('https://example-digital.test/careers/laravel-developer')
        ->and($run->refresh()->signals_found)->toBe(1);
});

it('stores nothing for a blank job', function () {
    Http::fake(['*' => Http::response(jsonFixture('reed/job-blank.json'))]);

    runDetail(new FetchReedJobDetailJob(1));

    expect(Signal::count())->toBe(0);
});

it('runs on the sources queue with unique ids and limit-friendly retry settings', function () {
    $search = new FetchReedJobsJob('laravel', 2, 5);
    $detail = new FetchReedJobDetailJob(123);

    expect($search->queue)->toBe('sources')
        ->and($detail->queue)->toBe('sources')
        ->and($search->uniqueId())->toBe('reed-search:laravel:2:5')
        ->and($detail->uniqueId())->toBe('reed-job:123')
        ->and($detail->timeout)->toBe(30)
        ->and($detail->maxExceptions)->toBe(3)
        ->and($detail->backoff)->toBe([30, 120, 600])
        ->and($detail->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addDays(2)->getTimestamp());
});
