<?php

declare(strict_types=1);

use App\Actions\IngestLaravelPartnerAction;
use App\Enums\ExternalService;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Enums\SuppressionType;
use App\Exceptions\ExternalServiceException;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Jobs\Sources\FetchLaravelPartnersJob;
use App\Models\ApiUsage;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Signal;
use App\Models\Suppression;
use App\Services\Crawler\RobotsTxtGuard;
use App\Services\Directories\LaravelPartnersService;
use App\Services\Support\DailyTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

function fakePartnersSite(string $robots = 'robots/allow.txt', ?string $page = null): void
{
    $fixtures = dirname(__DIR__, 2).'/Fixtures';

    Http::fake([
        'laravel.com/robots.txt' => Http::response((string) file_get_contents("{$fixtures}/{$robots}"), 200, ['Content-Type' => 'text/plain']),
        'laravel.com/partners' => Http::response($page ?? (string) file_get_contents("{$fixtures}/laravel-partners/partners.html"), 200, ['Content-Type' => 'text/html']),
    ]);
}

function runPartnersJob(?int $runId = null): FetchLaravelPartnersJob
{
    $job = (new FetchLaravelPartnersJob($runId))->withFakeQueueInteractions();
    $job->handle(app(LaravelPartnersService::class), app(RobotsTxtGuard::class), app(DailyTarget::class), app(IngestLaravelPartnerAction::class));

    return $job;
}

it('reads robots.txt first, then the partner list, and sends UK partners into the pipeline', function () {
    $run = ScrapeRun::factory()->create();
    fakePartnersSite();

    runPartnersJob($run->id)->assertReleased();
    expect(Company::count())->toBe(0);

    $this->travel(5)->seconds();
    runPartnersJob($run->id)->assertNotReleased();

    Http::assertSent(fn (Request $request) => str_contains($request->header('User-Agent')[0], 'LeadRadarBot'));
    Http::assertSentCount(2);

    $companies = Company::query()->orderBy('id')->get()->keyBy('name');

    // Demo Werkstatt (Berlin) and Test Labs (North America only) are not UK partners.
    expect($companies->keys()->all())->toBe(['Example Digital', 'Sample Studio'])
        ->and($companies['Example Digital']->domain)->toBe('example-digital.co.uk')
        ->and($companies['Example Digital']->website)->toBe('https://example-digital.co.uk')
        ->and($companies['Example Digital']->country)->toBe('GB')
        ->and($companies['Example Digital']->status)->toBe(LeadStatus::Enriching)
        ->and($companies['Example Digital']->pipeline_step)->toBe(PipelineStep::RegistrySearch)
        ->and($companies['Sample Studio']->domain)->toBe('sample-studio.test');

    $signal = $companies['Example Digital']->signals()->sole();

    expect($signal->source)->toBe(SignalSource::LaravelPartners)
        ->and($signal->external_id)->toBe('example-digital')
        ->and($signal->title)->toBe('Laravel Partner (Premier)')
        ->and($signal->description)->toBe('Laravel specialists building platforms & portals.')
        ->and($signal->url)->toBe('https://laravel.com/partners/example-digital')
        ->and($run->refresh()->companies_created)->toBe(2)
        ->and($run->signals_found)->toBe(2)
        ->and(ApiUsage::query()->where('service', ExternalService::Crawler)->count())->toBe(2);

    Queue::assertPushed(SearchCompaniesHouseJob::class, 2);
});

it('is idempotent across runs', function () {
    fakePartnersSite();
    app(RobotsTxtGuard::class)->remember('laravel.com', app(RobotsTxtGuard::class)->parse(''));

    runPartnersJob();
    $this->travel(5)->seconds();
    runPartnersJob();

    expect(Company::count())->toBe(2)->and(Signal::count())->toBe(2);
});

it('requests nothing but robots.txt when the directory disallows crawling', function () {
    fakePartnersSite(robots: 'robots/block-all.txt');

    runPartnersJob();
    $this->travel(5)->seconds();
    runPartnersJob();

    Http::assertSentCount(1);
    expect(Company::count())->toBe(0);
});

it('adds the listing to a company already known by domain and skips suppressed ones', function () {
    $known = Company::factory()->create(['domain' => 'example-digital.co.uk']);
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'sample-studio.test']);
    fakePartnersSite();
    app(RobotsTxtGuard::class)->remember('laravel.com', app(RobotsTxtGuard::class)->parse(''));

    runPartnersJob();

    expect(Company::count())->toBe(1)
        ->and($known->signals()->sole()->source)->toBe(SignalSource::LaravelPartners)
        ->and($known->pipelineEvents()->latest('id')->first()?->message)->toBe('Listed as a Laravel Partner');

    Queue::assertNotPushed(SearchCompaniesHouseJob::class);
});

it('fails loudly when the page no longer carries the partner data', function () {
    fakePartnersSite(page: '<html><body>Partners</body></html>');
    app(RobotsTxtGuard::class)->remember('laravel.com', app(RobotsTxtGuard::class)->parse(''));

    runPartnersJob();
})->throws(ExternalServiceException::class, 'no Inertia page data');
