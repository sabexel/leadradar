<?php

declare(strict_types=1);

use App\Actions\IngestJobAdAction;
use App\DTOs\JobBoards\JobAdData;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Signal;
use App\Models\Suppression;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function jobAd(string $id = '1001', string $employer = 'Example Digital Ltd', SignalSource $source = SignalSource::Reed, ?string $description = 'Laravel role in our small agency.'): JobAdData
{
    return new JobAdData(
        source: $source,
        externalId: $id,
        title: 'Laravel Developer',
        employerName: $employer,
        employerExternalId: null,
        location: 'Manchester',
        description: $description,
        url: "https://jobs.example.test/{$id}",
        externalUrl: null,
        postedAt: CarbonImmutable::parse('2026-11-01'),
        raw: ['id' => $id],
    );
}

function ingest(JobAdData $ad, ?ScrapeRun $run = null): ?Signal
{
    return app(IngestJobAdAction::class)->run($ad, $run);
}

it('creates a company, links the signal and queues the registry search', function () {
    $signal = ingest(jobAd());

    $company = Company::sole();

    expect($signal->company_id)->toBe($company->id)
        ->and($company->name)->toBe('Example Digital Ltd')
        ->and($company->normalised_name)->toBe('example digital')
        ->and($company->location)->toBe('Manchester')
        ->and($company->country)->toBe('GB')
        ->and($company->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipeline_step)->toBe(PipelineStep::RegistrySearch);

    Queue::assertPushed(SearchCompaniesHouseJob::class, fn ($job) => $job->companyId === $company->id);
});

it('gives one signal and one company when the same ad is ingested twice', function () {
    $run = ScrapeRun::factory()->create();

    ingest(jobAd(), $run);
    ingest(jobAd(), $run);

    expect(Signal::count())->toBe(1)
        ->and(Company::count())->toBe(1)
        ->and($run->refresh()->signals_found)->toBe(1)
        ->and($run->companies_created)->toBe(1);
});

it('attaches different ads from the same employer to one company, across sources and name variants', function () {
    $run = ScrapeRun::factory()->create();

    ingest(jobAd('1001', 'Example Digital Ltd'), $run);
    ingest(jobAd('1002', 'EXAMPLE DIGITAL LIMITED'), $run);
    ingest(jobAd('ad-9', 'Example Digital UK Ltd.', SignalSource::Adzuna), $run);

    expect(Company::count())->toBe(1)
        ->and(Company::sole()->signals)->toHaveCount(3)
        ->and($run->refresh()->signals_found)->toBe(3)
        ->and($run->companies_created)->toBe(1);
});

it('keeps different employers as different companies', function () {
    ingest(jobAd('1', 'Example Digital Ltd'));
    ingest(jobAd('2', 'Example Digital Studio Ltd'));

    expect(Company::count())->toBe(2);
});

it('skips recruiter employers entirely', function () {
    $run = ScrapeRun::factory()->create();

    $signal = ingest(jobAd('1', 'Sample Example Recruitment'), $run);

    expect($signal)->toBeNull()
        ->and(Company::count())->toBe(0)
        ->and(Signal::count())->toBe(0)
        ->and($run->refresh()->signals_found)->toBe(0);
});

it('skips ads posted on behalf of a client', function () {
    expect(ingest(jobAd('1', 'Example Consulting Ltd', description: 'Our client is a growing SaaS business.')))->toBeNull()
        ->and(Company::count())->toBe(0);
});

it('does not re-advance or change an existing company that already left discovery', function () {
    $existing = Company::factory()->rejected(RejectReason::NoValidEmail)->create([
        'name' => 'Example Digital Ltd',
        'normalised_name' => 'example digital',
    ]);

    ingest(jobAd());

    expect(Company::count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(LeadStatus::Rejected)
        ->and($existing->signals)->toHaveCount(1)
        ->and($existing->pipelineEvents)->toHaveCount(0);
});

it('does not suppress a brand-new company: it has no domain or number to match yet', function () {
    // Suppression applies once identifiers are known (registry number in P1.4, domain in P1.6).
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'example-digital.test']);

    ingest(jobAd());

    expect(Company::sole()->status)->toBe(LeadStatus::Enriching);
});

it('ignores employer names that normalise to nothing', function () {
    expect(ingest(jobAd('1', ' -- !! ')))->toBeNull()
        ->and(Company::count())->toBe(0);
});
