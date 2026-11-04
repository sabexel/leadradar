<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyWebsiteResultAction;
use App\Enums\ExternalService;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\FindCompanyWebsiteJob;
use App\Models\ApiUsage;
use App\Models\Company;
use App\Models\Setting;
use App\Models\Suppression;
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

function companyNeedingWebsite(array $attributes = []): Company
{
    return Company::factory()->create([
        'name' => 'Example Digital Ltd',
        'legal_name' => 'EXAMPLE DIGITAL LTD',
        'location' => 'Manchester, Greater Manchester',
        'website' => null,
        'domain' => null,
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::FindWebsite,
        ...$attributes,
    ]);
}

function runWebsiteJob(Company $company): FindCompanyWebsiteJob
{
    $job = (new FindCompanyWebsiteJob($company->id))->withFakeQueueInteractions();
    $job->handle(app(BraveSearchService::class), app(ApplyWebsiteResultAction::class), app(AdvanceLeadPipelineAction::class));

    return $job;
}

it('queries Brave for the legal name and town, skips directories and stores the real site', function () {
    Http::fake(['api.search.brave.com/res/v1/web/search*' => Http::response(jsonFixture('brave/website-directory-first.json'))]);
    $company = companyNeedingWebsite();

    runWebsiteJob($company);

    Http::assertSent(fn (Request $request) => $request['q'] === '"EXAMPLE DIGITAL LTD" Manchester'
        && $request['country'] === 'GB'
        && (int) $request['count'] === 10
        && $request->header('X-Subscription-Token')[0] === 'test-brave-key');

    $company->refresh();
    $event = $company->pipelineEvents()->where('step', PipelineStep::FindWebsite)->sole();

    expect($company->website)->toBe('https://www.example-digital.test')
        ->and($company->domain)->toBe('example-digital.test')
        ->and($company->pipeline_step)->toBe(PipelineStep::Crawl)
        ->and($event->outcome)->toBe(PipelineOutcome::Ok)
        ->and($event->context['source_url'])->toBe('https://www.example-digital.test/')
        ->and(ApiUsage::sole()->service)->toBe(ExternalService::Brave);
});

it('rejects with no_website when nothing matches', function (string $fixture) {
    Http::fake(['*' => Http::response(jsonFixture("brave/{$fixture}.json"))]);
    $company = companyNeedingWebsite();

    runWebsiteJob($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::NoWebsite)
        ->and($company->website)->toBeNull();
})->with(['empty', 'website-no-match']);

it('suppresses the company once its found domain matches the suppression list', function () {
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'example-digital.test']);
    Http::fake(['*' => Http::response(jsonFixture('brave/website-directory-first.json'))]);
    $company = companyNeedingWebsite();

    runWebsiteJob($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Suppressed)
        ->and($company->domain)->toBe('example-digital.test');
});

it('rejects a second company that resolves to a website another lead already has', function () {
    $owner = Company::factory()->create(['domain' => 'example-digital.test']);
    Http::fake(['*' => Http::response(jsonFixture('brave/website-directory-first.json'))]);
    $company = companyNeedingWebsite();

    runWebsiteJob($company);

    $company->refresh();

    expect($company->reject_reason)->toBe(RejectReason::NoWebsite)
        ->and($company->domain)->toBeNull()
        ->and($company->pipelineEvents()->sole()->context['company_id'])->toBe($owner->id);
});

it('releases until tomorrow when the Brave daily cap is used up', function () {
    Setting::put('cap.brave.per_day', 0);
    Http::fake();
    $company = companyNeedingWebsite();

    $job = runWebsiteJob($company);

    $job->assertReleased(21 * 3600);
    Http::assertNothingSent();
    expect($company->refresh()->pipeline_step)->toBe(PipelineStep::FindWebsite);
});

it('skips the search when the website is already known', function () {
    Http::fake();
    $company = companyNeedingWebsite(['website' => 'example-digital.test/about']);

    app(AdvanceLeadPipelineAction::class)->run($company);

    expect($company->refresh()->website)->toBe('https://example-digital.test')
        ->and($company->domain)->toBe('example-digital.test')
        ->and($company->pipeline_step)->toBe(PipelineStep::Crawl);

    Http::assertNothingSent();
    Queue::assertNotPushed(FindCompanyWebsiteJob::class);
});

it('is queued by the pipeline when the website is unknown', function () {
    $company = companyNeedingWebsite();

    app(AdvanceLeadPipelineAction::class)->run($company);

    Queue::assertPushed(FindCompanyWebsiteJob::class, fn ($job) => $job->companyId === $company->id && $job->queue === 'web');
});

it('does nothing when the company is not waiting on find_website', function () {
    Http::fake();
    $company = companyNeedingWebsite(['pipeline_step' => PipelineStep::Crawl]);

    runWebsiteJob($company);

    Http::assertNothingSent();
});
