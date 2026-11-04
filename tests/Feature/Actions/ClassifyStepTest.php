<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Enums\CompanyType;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Jobs\Enrichment\FindCompanyWebsiteJob;
use App\Models\Company;
use App\Models\Person;
use App\Models\Signal;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function companyToClassify(array $attributes = [], ?string $adText = null, ?string $founderCountry = 'England'): Company
{
    $company = Company::factory()->create([
        'name' => 'Example Digital Ltd',
        'legal_name' => 'EXAMPLE DIGITAL LTD',
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::Classify,
        'company_type' => CompanyType::Unknown,
        'sic_codes' => ['62012'],
        'website' => null,
        'domain' => null,
        ...$attributes,
    ]);

    if ($adText !== null) {
        Signal::factory()->for($company)->create(['title' => 'Laravel Developer', 'description' => $adText]);
    }

    Person::factory()->for($company)->create(['country_of_residence' => $founderCountry]);

    return $company;
}

function runPipeline(Company $company): Company
{
    app(AdvanceLeadPipelineAction::class)->run($company);

    return $company->refresh();
}

it('classifies an agency and moves on to find_website', function () {
    $company = runPipeline(companyToClassify(adText: 'Small web agency building Laravel platforms for our clients.'));

    $event = $company->pipelineEvents()->sole();

    expect($company->company_type)->toBe(CompanyType::Agency)
        ->and($company->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipeline_step)->toBe(PipelineStep::FindWebsite)
        ->and($event->step)->toBe(PipelineStep::Classify)
        ->and($event->outcome)->toBe(PipelineOutcome::Ok)
        ->and($event->context['type'])->toBe('agency');
});

it('classifies an in-house product team as a direct client', function () {
    $company = runPipeline(companyToClassify(adText: 'Join the in-house team behind our SaaS product, built on Laravel.'));

    expect($company->company_type)->toBe(CompanyType::DirectClient)
        ->and($company->pipeline_step)->toBe(PipelineStep::FindWebsite);
});

it('rejects recruiters found by SIC code at the classify step', function () {
    $company = runPipeline(companyToClassify(['sic_codes' => ['78109']], 'Laravel Developer wanted.'));

    expect($company->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::Recruiter)
        ->and($company->company_type)->toBe(CompanyType::Recruiter)
        ->and($company->pipeline_step)->toBe(PipelineStep::Classify)
        ->and($company->pipelineEvents()->sole()->outcome)->toBe(PipelineOutcome::Rejected);
});

it('rejects offshore shops early and stores the evidence', function () {
    $company = runPipeline(companyToClassify(
        adText: 'Offshore Laravel team with a delivery centre in Lahore. Dedicated developers for UK agencies.',
        founderCountry: 'Pakistan',
    ));

    expect($company->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::Offshore)
        ->and($company->company_type)->toBe(CompanyType::Offshore)
        ->and(collect($company->offshore_evidence)->pluck('type')->all())->toContain('officers_abroad');
});

it('runs the officers job result straight through classify', function () {
    // After the officers step the company sits at `classify`; advancing must not stop there.
    $company = runPipeline(companyToClassify(adText: 'Digital agency, Laravel and React.'));

    expect($company->pipeline_step)->toBe(PipelineStep::FindWebsite);
    Queue::assertPushed(FindCompanyWebsiteJob::class, fn ($job) => $job->companyId === $company->id);
});
