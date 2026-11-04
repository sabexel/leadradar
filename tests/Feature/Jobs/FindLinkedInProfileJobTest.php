<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyLinkedInResultAction;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Jobs\Enrichment\FindLinkedInProfileJob;
use App\Models\Company;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Search\BraveSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-04 09:00:00');
    config(['services.brave.key' => 'test-brave-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function companyAwaitingLinkedIn(): Company
{
    $company = Company::factory()->create([
        'name' => 'Example Digital Ltd',
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::FindLinkedIn,
    ]);

    Person::factory()->for($company)->position(1)->create(['first_name' => 'Jane', 'last_name' => 'Example']);
    Person::factory()->for($company)->position(2)->create(['first_name' => 'John', 'last_name' => 'Sample']);

    return $company;
}

function runLinkedInJobs(Company $company): void
{
    app(AdvanceLeadPipelineAction::class)->run($company);

    $ran = [];

    for ($i = 0; $i < 10; $i++) {
        $queued = collect(Queue::pushed(FindLinkedInProfileJob::class))->first(fn ($j) => ! in_array(spl_object_id($j), $ran, true));

        if ($queued === null) {
            return;
        }

        $ran[] = spl_object_id($queued);
        runLinkedInJob($queued);
    }
}

function runLinkedInJob(FindLinkedInProfileJob $queued): FindLinkedInProfileJob
{
    $job = (new FindLinkedInProfileJob($queued->companyId, $queued->personId))->withFakeQueueInteractions();
    $job->handle(app(BraveSearchService::class), app(ApplyLinkedInResultAction::class), app(AdvanceLeadPipelineAction::class));

    return $job;
}

it('searches Brave per founder, stores the canonical profile URL with its source, and never calls LinkedIn', function () {
    Http::fake(function (Request $request) {
        return str_contains($request['q'], '"Jane Example"')
            ? Http::response(jsonFixture('brave/linkedin-jane.json'))
            : Http::response(jsonFixture('brave/empty.json'));
    });
    $company = companyAwaitingLinkedIn();

    runLinkedInJobs($company);

    $jane = $company->founders()->first();
    $john = $company->founders()->get()->last();

    Http::assertSent(fn (Request $r) => $r['q'] === 'site:linkedin.com/in "Jane Example" "Example Digital"' && $r['country'] === 'GB');
    Http::assertSent(fn (Request $r) => $r['q'] === 'site:linkedin.com/in "John Sample" "Example Digital"');
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $r) => str_contains((string) parse_url($r->url(), PHP_URL_HOST), 'linkedin.com'));

    expect($jane->linkedin_url)->toBe('https://www.linkedin.com/in/jane-example-4b2a1')
        ->and($jane->linkedin_source_url)->toBe('https://uk.linkedin.com/in/jane-example-4b2a1?trk=public_profile')
        ->and($jane->linkedin_checked_at)->not->toBeNull()
        ->and($john->linkedin_url)->toBeNull()
        ->and($john->linkedin_checked_at)->not->toBeNull()
        ->and($company->refresh()->pipeline_step)->toBe(PipelineStep::Done)
        ->and($company->pipelineEvents()->where('step', PipelineStep::FindLinkedIn)->sole()->context)->toEqual(['searched' => 2, 'found' => 1]);
});

it('skips founders that already have a URL or were searched within 30 days', function () {
    Http::fake(['*' => Http::response(jsonFixture('brave/empty.json'))]);
    $company = companyAwaitingLinkedIn();
    $company->founders()->first()->update(['linkedin_url' => 'https://www.linkedin.com/in/jane-example']);
    $company->founders()->get()->last()->update(['linkedin_checked_at' => now()->subDays(5)]);

    runLinkedInJobs($company);

    Http::assertNothingSent();
    expect($company->refresh()->pipeline_step)->toBe(PipelineStep::Done);
});

it('searches again after 30 days', function () {
    Http::fake(['*' => Http::response(jsonFixture('brave/empty.json'))]);
    $company = companyAwaitingLinkedIn();
    $company->founders()->get()->each->update(['linkedin_checked_at' => now()->subDays(31)]);

    runLinkedInJobs($company);

    Http::assertSentCount(2);
});

it('releases until 06:00 London when the Brave cap is used up', function () {
    Setting::put('cap.brave.per_day', 0);
    Http::fake();
    $company = companyAwaitingLinkedIn();
    app(AdvanceLeadPipelineAction::class)->run($company);

    $job = runLinkedInJob(Queue::pushed(FindLinkedInProfileJob::class)->first());

    $job->assertReleased(21 * 3600);
    Http::assertNothingSent();
    expect($company->refresh()->pipeline_step)->toBe(PipelineStep::FindLinkedIn);
});

it('does not query twice when a job is retried', function () {
    Http::fake(['*' => Http::response(jsonFixture('brave/empty.json'))]);
    $company = companyAwaitingLinkedIn();
    app(AdvanceLeadPipelineAction::class)->run($company);
    $first = Queue::pushed(FindLinkedInProfileJob::class)->first();

    runLinkedInJob($first);
    runLinkedInJob($first);

    Http::assertSentCount(1);
});

it('uses the trading name without the legal suffix in the query', function (string $name, string $expected) {
    expect(app(ApplyLinkedInResultAction::class)->companyQueryName(new Company(['name' => $name])))->toBe($expected);
})->with([
    ['Example Digital Ltd', 'Example Digital'],
    ['Example Digital Limited', 'Example Digital'],
    ['Sample Partners LLP', 'Sample Partners'],
    ['Example Digital Ltd.', 'Example Digital'],
    ['Example Digital', 'Example Digital'],
]);

it('queues one job per person on the web queue', function () {
    $company = companyAwaitingLinkedIn();

    app(AdvanceLeadPipelineAction::class)->run($company);

    $job = Queue::pushed(FindLinkedInProfileJob::class)->sole();

    expect($job->queue)->toBe('web')
        ->and($job->personId)->toBe($company->founders()->first()->id)
        ->and($job->uniqueId())->toBe("linkedin:{$job->personId}");
});
