<?php

declare(strict_types=1);

use App\Actions\IngestRegistryCompanyAction;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\FetchCompanyProfileJob;
use App\Jobs\Sources\FetchCompaniesHouseSicJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Models\Suppression;
use App\Services\CompaniesHouse\CompaniesHouseService;
use App\Services\Support\DailyTarget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-04 06:10:00');
    config(['services.companies_house.key' => 'test-ch-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function runSicPage(FetchCompaniesHouseSicJob $job): FetchCompaniesHouseSicJob
{
    $job->withFakeQueueInteractions()->handle(app(CompaniesHouseService::class), app(DailyTarget::class), app(IngestRegistryCompanyAction::class));

    return $job;
}

function fakeAdvancedSearch(int $hits = 3): void
{
    Http::fake(['api.company-information.service.gov.uk/advanced-search/companies*' => Http::response([...jsonFixture('companies-house/advanced-search.json'), 'hits' => $hits])]);
}

it('sends registry matches straight to the profile step, skipping forms the gates reject', function () {
    $run = ScrapeRun::factory()->create();
    fakeAdvancedSearch();

    runSicPage(new FetchCompaniesHouseSicJob('62012', 'Manchester', 10, 1, $run->id));

    $companies = Company::query()->orderBy('id')->get();
    $example = $companies->first();

    // DEMO COMMUNITY TECH is a guarantee company → never created.
    expect($companies->pluck('name')->all())->toBe(['Example Digital Ltd', 'Sample Software Partners LLP'])
        ->and($example->registration_number)->toBe('01234567')
        ->and($example->legal_name)->toBe('EXAMPLE DIGITAL LTD')
        ->and($example->location)->toBe('Manchester')
        ->and($example->sic_codes)->toBe(['62012', '73110'])
        ->and($example->status)->toBe(LeadStatus::Enriching)
        ->and($example->pipeline_step)->toBe(PipelineStep::RegistryProfile)
        ->and($example->signals()->sole()->source)->toBe(SignalSource::CompaniesHouseSic)
        ->and($example->signals()->sole()->title)->toBe('SIC 62012 in Manchester')
        ->and($run->refresh()->companies_created)->toBe(2);

    Queue::assertPushed(FetchCompanyProfileJob::class, 2);
});

it('takes only its share, remembers the position and continues there next time', function () {
    fakeAdvancedSearch(hits: 120);

    runSicPage(new FetchCompaniesHouseSicJob('62012', 'Manchester', 1));

    expect(Company::count())->toBe(1)
        ->and(Setting::get(FetchCompaniesHouseSicJob::cursorKey('62012', 'Manchester')))->toBe(1);

    Queue::assertNotPushed(FetchCompaniesHouseSicJob::class);

    runSicPage(new FetchCompaniesHouseSicJob('62012', 'Manchester', 1));

    Http::assertSent(fn (Request $request) => (int) $request['start_index'] === 1);
});

it('moves past known and suppressed companies and queues the next page while its share is not used', function () {
    Company::factory()->create(['registration_number' => '01234567']);
    Suppression::factory()->create(['type' => SuppressionType::CompanyNumber, 'value' => 'oc123456']);
    fakeAdvancedSearch(hits: 120);

    runSicPage(new FetchCompaniesHouseSicJob('62012', 'Manchester', 5, 1, 7));

    expect(Signal::count())->toBe(0)
        ->and(Setting::get(FetchCompaniesHouseSicJob::cursorKey('62012', 'Manchester')))->toBe(3);

    Queue::assertPushed(FetchCompaniesHouseSicJob::class, fn ($job) => $job->page === 2 && $job->take === 5 && $job->scrapeRunId === 7);
});

it('starts again from the top once the list is used up', function () {
    Setting::put(FetchCompaniesHouseSicJob::cursorKey('62012', 'Manchester'), 0);
    fakeAdvancedSearch(hits: 3);

    runSicPage(new FetchCompaniesHouseSicJob('62012', 'Manchester', 10));

    expect(Setting::get(FetchCompaniesHouseSicJob::cursorKey('62012', 'Manchester')))->toBe(0);
    Queue::assertNotPushed(FetchCompaniesHouseSicJob::class);
});

it('makes no call once the source\'s daily cap is used', function () {
    Setting::put('source.ch_sic.per_day', 1);
    Signal::factory()->create(['source' => SignalSource::CompaniesHouseSic]);
    Http::fake();

    runSicPage(new FetchCompaniesHouseSicJob('62012', 'Manchester', 5));

    Http::assertNothingSent();
});

it('only asks for companies older than the minimum age', function () {
    Setting::put('source.ch_sic.min_age_years', 3);
    fakeAdvancedSearch();

    runSicPage(new FetchCompaniesHouseSicJob('62012', 'Manchester', 5));

    Http::assertSent(fn (Request $request) => $request['incorporated_to'] === '2023-11-04');
});
