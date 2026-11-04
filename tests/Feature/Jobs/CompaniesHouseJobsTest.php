<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCompaniesHouseResultAction;
use App\Actions\IngestJobAdAction;
use App\DTOs\JobBoards\JobAdData;
use App\Enums\DataSource;
use App\Enums\ExternalService;
use App\Enums\LeadStatus;
use App\Enums\PersonRole;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Enums\SuppressionType;
use App\Exceptions\ExternalServiceException;
use App\Jobs\Enrichment\FetchCompanyOfficersJob;
use App\Jobs\Enrichment\FetchCompanyProfileJob;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Models\Company;
use App\Models\Person;
use App\Models\Signal;
use App\Models\Suppression;
use App\Services\CompaniesHouse\CompaniesHouseService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.companies_house.key' => 'test-ch-key']);
    Queue::fake();
});

function discoveredCompany(array $attributes = []): Company
{
    return Company::factory()->discovered()->create([
        'name' => 'Example Digital Ltd',
        'normalised_name' => 'example digital',
        'location' => null,
        'status' => LeadStatus::Enriching,
        ...$attributes,
    ]);
}

function companyAtStep(PipelineStep $step, array $attributes = []): Company
{
    return Company::factory()->create([
        'name' => 'Example Digital Ltd',
        'registration_number' => '01234567',
        'status' => LeadStatus::Enriching,
        'pipeline_step' => $step,
        'company_status' => null,
        'company_kind' => null,
        'sic_codes' => null,
        'accounts_category' => null,
        'location' => null,
        ...$attributes,
    ]);
}

function handle(object $job): void
{
    $job->withFakeQueueInteractions()->handle(
        app(CompaniesHouseService::class),
        app(ApplyCompaniesHouseResultAction::class),
        app(AdvanceLeadPipelineAction::class),
    );
}

function fakeProfile(array $overrides = []): void
{
    Http::fake(['api.company-information.service.gov.uk/company/01234567' => Http::response(
        array_replace_recursive(jsonFixture('companies-house/profile.json'), $overrides),
    )]);
}

// --- registry_search -----------------------------------------------------------------------

it('matches the exact active company and queues the profile step', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-exact.json'))]);
    $company = discoveredCompany();

    handle(new SearchCompaniesHouseJob($company->id));

    $company->refresh();
    $event = $company->pipelineEvents()->sole();

    expect($company->registration_number)->toBe('01234567')
        ->and($company->legal_name)->toBe('EXAMPLE DIGITAL LTD')
        ->and($company->registry)->toBe('companies_house')
        ->and($company->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipeline_step)->toBe(PipelineStep::RegistryProfile)
        ->and($event->outcome)->toBe(PipelineOutcome::Ok)
        ->and($event->context['similarity'])->toEqual(1.0);

    Queue::assertPushed(FetchCompanyProfileJob::class, fn ($job) => $job->companyId === $company->id);
});

it('rejects an ambiguous match and logs the candidates for review', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-ambiguous.json'))]);
    $company = discoveredCompany();

    handle(new SearchCompaniesHouseJob($company->id));

    $company->refresh();
    $event = $company->pipelineEvents()->sole();

    expect($company->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::NotOnRegistry)
        ->and($company->registration_number)->toBeNull()
        ->and($event->outcome)->toBe(PipelineOutcome::Rejected)
        ->and(collect($event->context['candidates'])->pluck('number')->all())->toEqualCanonicalizing(['01234567', '08888888']);

    Queue::assertNothingPushed();
});

it('breaks a tie with the signal town', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-ambiguous.json'))]);
    $company = discoveredCompany(['location' => 'Leeds, West Yorkshire']);

    handle(new SearchCompaniesHouseJob($company->id));

    expect($company->refresh()->registration_number)->toBe('08888888')
        ->and($company->pipeline_step)->toBe(PipelineStep::RegistryProfile);
});

it('rejects a dissolved company as not active without further calls', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-dissolved.json'))]);
    $company = discoveredCompany();

    handle(new SearchCompaniesHouseJob($company->id));

    expect($company->refresh()->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::NotActive)
        ->and($company->registration_number)->toBe('00999999')
        ->and($company->company_status)->toBe('dissolved');

    Queue::assertNothingPushed();
});

it('rejects when nothing on the register resembles the name', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-none.json'))]);
    $company = discoveredCompany();

    handle(new SearchCompaniesHouseJob($company->id));

    expect($company->refresh()->reject_reason)->toBe(RejectReason::NotOnRegistry)
        ->and($company->pipelineEvents()->sole()->context['candidates'][0]['number'])->toBe('05555555');
});

it('merges a second employer name that resolves to an already-known company number', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-exact.json'))]);
    $existing = companyAtStep(PipelineStep::Classify, ['name' => 'Example Digital (trading name)']);
    $company = discoveredCompany();
    $signal = Signal::factory()->for($company)->create();

    handle(new SearchCompaniesHouseJob($company->id));

    expect(Company::find($company->id))->toBeNull()
        ->and($signal->refresh()->company_id)->toBe($existing->id)
        ->and($existing->pipelineEvents()->sole()->message)->toContain('Merged duplicate');

    Queue::assertNothingPushed();
});

it('suppresses a company whose registration number is on the list, before the profile call', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-exact.json'))]);
    Suppression::factory()->create(['type' => SuppressionType::CompanyNumber, 'value' => '01234567']);
    $company = discoveredCompany();

    handle(new SearchCompaniesHouseJob($company->id));

    expect($company->refresh()->status)->toBe(LeadStatus::Suppressed);
    Queue::assertNothingPushed();
});

// --- registry_profile ----------------------------------------------------------------------

it('stores the profile and queues the officers step for an active ltd', function () {
    fakeProfile();
    $company = companyAtStep(PipelineStep::RegistryProfile);

    handle(new FetchCompanyProfileJob($company->id));

    $company->refresh();

    expect($company->pipeline_step)->toBe(PipelineStep::RegistryOfficers)
        ->and($company->status)->toBe(LeadStatus::Enriching)
        ->and($company->company_status)->toBe('active')
        ->and($company->company_kind)->toBe('ltd')
        ->and($company->sic_codes)->toBe(['62012', '73110'])
        ->and($company->accounts_category)->toBe('micro-entity')
        ->and($company->incorporated_on?->toDateString())->toBe('2015-02-10')
        ->and($company->location)->toBe('Manchester')
        ->and($company->registered_address['postal_code'])->toBe('M1 1AA');

    Queue::assertPushed(FetchCompanyOfficersJob::class);
});

it('accepts LLPs and PLCs', function (string $type) {
    fakeProfile(['type' => $type]);
    $company = companyAtStep(PipelineStep::RegistryProfile);

    handle(new FetchCompanyProfileJob($company->id));

    expect($company->refresh()->pipeline_step)->toBe(PipelineStep::RegistryOfficers);
})->with(['llp', 'plc']);

it('applies the registry gates', function (array $overrides, RejectReason $reason) {
    fakeProfile($overrides);
    $company = companyAtStep(PipelineStep::RegistryProfile);

    handle(new FetchCompanyProfileJob($company->id));

    expect($company->refresh()->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe($reason)
        ->and($company->pipelineEvents()->sole()->step)->toBe(PipelineStep::RegistryProfile);

    Queue::assertNothingPushed();
})->with([
    'dissolved' => [['company_status' => 'dissolved'], RejectReason::NotActive],
    'liquidation' => [['company_status' => 'liquidation'], RejectReason::NotActive],
    'liquidated flag' => [['has_been_liquidated' => true], RejectReason::NotActive],
    'unlimited' => [['type' => 'private-unlimited'], RejectReason::WrongLegalForm],
    'limited partnership' => [['type' => 'limited-partnership'], RejectReason::WrongLegalForm],
    'dormant' => [['accounts' => ['last_accounts' => ['type' => 'dormant']]], RejectReason::Dormant],
]);

it('rejects when the company number no longer exists', function () {
    Http::fake(['*' => Http::response([], 404)]);
    $company = companyAtStep(PipelineStep::RegistryProfile);

    handle(new FetchCompanyProfileJob($company->id));

    expect($company->refresh()->reject_reason)->toBe(RejectReason::NotOnRegistry);
});

// --- registry_officers ---------------------------------------------------------------------

it('stores directors, picks the earliest-appointed active ones as founders, and never stores DOB', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/officers.json'))]);
    $company = companyAtStep(PipelineStep::RegistryOfficers);

    handle(new FetchCompanyOfficersJob($company->id));

    $company->refresh();
    $people = $company->people()->orderBy('id')->get();

    expect($people->map(fn (Person $p) => $p->full_name)->all())
        ->toEqualCanonicalizing(['John Sample', 'Jane Example', 'Alex Demo', "Chris O'Example-Smith"])
        ->and($company->founders->map(fn (Person $p) => $p->full_name)->all())->toBe(['Jane Example', 'John Sample'])
        ->and($people->firstWhere('first_name', 'Chris')->position)->toBe(3)
        ->and($people->firstWhere('first_name', 'Alex')->position)->toBeNull()
        ->and($people->firstWhere('first_name', 'Alex')->resigned_on?->toDateString())->toBe('2019-01-01')
        ->and($people->every(fn (Person $p) => $p->role === PersonRole::Director && $p->source === DataSource::CompaniesHouse))->toBeTrue()
        ->and($people->first()->source_url)->toBe('https://find-and-update.company-information.service.gov.uk/company/01234567/officers')
        ->and(array_keys($people->first()->getAttributes()))->not->toContain('date_of_birth')
        ->and(json_encode($people->toArray()))->not->toContain('1985')
        // The pipeline then runs the local classify step straight away and stops at find_website.
        ->and($company->pipeline_step)->toBe(PipelineStep::FindWebsite)
        ->and($company->pipelineEvents()->where('step', PipelineStep::RegistryOfficers)->sole()->context)->toBe(['active' => 3, 'resigned' => 1])
        ->and($company->pipelineEvents()->where('step', PipelineStep::Classify)->exists())->toBeTrue();
});

it('is idempotent when the officers step runs twice', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/officers.json'))]);
    $company = companyAtStep(PipelineStep::RegistryOfficers);
    $apply = app(ApplyCompaniesHouseResultAction::class);
    $officers = app(CompaniesHouseService::class)->getOfficers('01234567');

    $apply->applyOfficers($company, $officers);
    $apply->applyOfficers($company->refresh(), $officers);

    expect($company->people()->count())->toBe(4)
        ->and($company->founders->pluck('first_name')->all())->toBe(['Jane', 'John']);
});

it('parses LLP members, including designated members, as founders', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/officers-llp.json'))]);
    $company = companyAtStep(PipelineStep::RegistryOfficers, ['company_kind' => 'llp']);

    handle(new FetchCompanyOfficersJob($company->id));

    $founders = $company->refresh()->founders;

    expect($founders->map(fn (Person $p) => $p->full_name)->all())->toBe(['Sam Partner', 'Lee Member'])
        ->and($founders->every(fn (Person $p) => $p->role === PersonRole::LlpMember))->toBeTrue()
        ->and($company->people()->count())->toBe(2); // corporate member excluded
});

// --- job behaviour -------------------------------------------------------------------------

it('does nothing when the company is not waiting on that step', function () {
    Http::fake();
    $company = companyAtStep(PipelineStep::Classify);

    handle(new SearchCompaniesHouseJob($company->id));
    handle(new FetchCompanyProfileJob($company->id));
    handle(new FetchCompanyOfficersJob($company->id));

    Http::assertNothingSent();
    expect($company->refresh()->pipeline_step)->toBe(PipelineStep::Classify);
});

it('marks the company as error when a job finally fails', function () {
    $company = companyAtStep(PipelineStep::RegistryProfile);

    (new FetchCompanyProfileJob($company->id))->failed(new ExternalServiceException(
        ExternalService::CompaniesHouse, 'returned HTTP 503', 503,
    ));

    $event = $company->pipelineEvents()->sole();

    expect($company->refresh()->status)->toBe(LeadStatus::Error)
        ->and($event->outcome)->toBe(PipelineOutcome::Failed)
        ->and($event->step)->toBe(PipelineStep::RegistryProfile)
        ->and($event->context['job'])->toBe('FetchCompanyProfileJob');
});

it('uses the registry queue with per-company unique ids', function () {
    expect((new SearchCompaniesHouseJob(5))->queue)->toBe('registry')
        ->and((new SearchCompaniesHouseJob(5))->uniqueId())->toBe('ch-search:5')
        ->and((new FetchCompanyProfileJob(5))->uniqueId())->toBe('ch-profile:5')
        ->and((new FetchCompanyOfficersJob(5))->uniqueId())->toBe('ch-officers:5');
});

it('starts the registry search when a new company is ingested', function () {
    $ad = new JobAdData(SignalSource::Reed, '1', 'Laravel Developer', 'Example Digital Ltd', null, 'Manchester', null, null, null, null, []);

    app(IngestJobAdAction::class)->run($ad);

    $company = Company::sole();

    expect($company->status)->toBe(LeadStatus::Enriching);
    Queue::assertPushed(SearchCompaniesHouseJob::class, fn ($job) => $job->companyId === $company->id);
});

it('does not start the UK registry search for non-UK companies', function () {
    $company = discoveredCompany(['country' => 'NL', 'status' => LeadStatus::Discovered]);

    app(AdvanceLeadPipelineAction::class)->run($company);

    Queue::assertNothingPushed();
    expect($company->refresh()->status)->toBe(LeadStatus::Discovered);
});
