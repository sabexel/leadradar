<?php

declare(strict_types=1);

use App\Actions\RetryPipelineStepAction;
use App\Enums\CompanyType;
use App\Enums\EmailVerificationStatus;
use App\Enums\ExternalService;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\FetchCompanyProfileJob;
use App\Jobs\Sources\FetchReedJobDetailJob;
use App\Models\ApiUsage;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Suppression;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/*
 * End-to-end (P1.12): one Reed ad → Companies House → classify → Brave website → crawl → email candidates →
 * MillionVerifier → Brave LinkedIn → qualify. Every outbound call is faked by one router; jobs are run in the
 * order the pipeline queues them.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-04 09:00:00'); // the Reed fixture ad was posted on 01/11/2026
    config([
        'services.reed.key' => 'test-reed-key',
        'services.companies_house.key' => 'test-ch-key',
        'services.brave.key' => 'test-brave-key',
        'services.millionverifier.key' => 'test-mv-key',
    ]);
    Queue::fake();
    Sleep::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function fixtureText(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/{$path}");
}

function html(string $path, array $headers = []): PromiseInterface
{
    return Http::response(fixtureText($path), 200, ['Content-Type' => 'text/html; charset=utf-8', ...$headers]);
}

/**
 * Fake every external system. $overrides: URL pattern (Str::is) → response or fn (Request) => response.
 * $verifier: address → MillionVerifier result (anything else: invalid).
 *
 * @param  array<string, mixed>  $overrides
 * @param  array<string, string>  $verifier
 */
function fakeWorld(array $overrides = [], ?array $verifier = null): void
{
    $verifier ??= [
        'jane@example-digital.test' => 'ok',
        'john.sample@example-digital.test' => 'ok',
        'hello@example-digital.test' => 'ok',
    ];

    Http::fake(function (Request $request) use ($overrides, $verifier) {
        $url = $request->url();

        foreach ($overrides as $pattern => $response) {
            if (Str::is($pattern, $url)) {
                return $response instanceof Closure ? $response($request) : $response;
            }
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            $host === 'www.reed.co.uk' => Http::response(jsonFixture('reed/job.json')),

            $host === 'api.company-information.service.gov.uk' && $path === '/search/companies' => Http::response(jsonFixture('companies-house/search-exact.json')),
            $host === 'api.company-information.service.gov.uk' && str_ends_with($path, '/officers') => Http::response(jsonFixture('companies-house/officers.json')),
            $host === 'api.company-information.service.gov.uk' => Http::response(jsonFixture('companies-house/profile.json')),

            $host === 'api.search.brave.com' && str_contains((string) $request['q'], 'site:linkedin.com/in') => Http::response(jsonFixture(
                str_contains((string) $request['q'], '"Jane Example"') ? 'brave/linkedin-jane.json' : 'brave/empty.json',
            )),
            $host === 'api.search.brave.com' => Http::response(jsonFixture('brave/website-directory-first.json')),

            $host === 'api.millionverifier.com' => Http::response([
                ...jsonFixture('millionverifier/'.($verifier[$request['email']] ?? 'invalid').'.json'),
                'email' => $request['email'],
            ]),

            $host === 'www.example-digital.test' => match ($path) {
                '/robots.txt' => Http::response(fixtureText('robots/allow.txt'), 200, ['Content-Type' => 'text/plain']),
                '/' => html('html/home.html', ['Set-Cookie' => 'laravel_session=abc; path=/']),
                '/contact' => html('html/contact.html'),
                '/about/' => html('html/about.html'),
                '/careers' => html('html/careers.html'),
                '/services/laravel' => html('html/services.html'),
                default => Http::response('Not found', 404, ['Content-Type' => 'text/html']),
            },

            default => throw new RuntimeException("Unexpected request in the pipeline test: {$url}"),
        };
    });
}

/**
 * Run every queued job (FIFO) until none are left; released jobs are re-run after the clock moves on.
 */
function drivePipeline(int $maxJobs = 120): int
{
    $done = [];
    $count = 0;

    while ($count < $maxJobs) {
        $next = null;

        foreach (Queue::pushedJobs() as $items) {
            foreach ($items as $item) {
                if (! in_array(spl_object_id($item['job']), $done, true)) {
                    $next ??= $item['job'];
                }
            }
        }

        if ($next === null) {
            return $count;
        }

        $done[] = spl_object_id($next);
        $count++;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $next->withFakeQueueInteractions();
            app()->call([$next, 'handle']);
            test()->travel(10)->seconds();

            if (! $next->job->isReleased()) {
                break;
            }
        }
    }

    throw new RuntimeException('Pipeline did not settle');
}

function runFromReedAd(?ScrapeRun $run = null): Company
{
    dispatch(new FetchReedJobDetailJob(50000001, $run?->id));
    drivePipeline();

    return Company::query()->where('normalised_name', 'example digital')->sole();
}

it('takes a Laravel job ad all the way to a qualified 10.0 lead', function () {
    fakeWorld();
    $run = ScrapeRun::factory()->create();

    $company = runFromReedAd($run);
    $jane = $company->founders()->first();
    $john = $company->founders()->get()->last();

    expect($company->status)->toBe(LeadStatus::Qualified)
        ->and($company->pipeline_step)->toBe(PipelineStep::Done)
        ->and($company->score)->toBe('10.0')
        ->and($company->qualified_at)->not->toBeNull()
        ->and($company->registration_number)->toBe('01234567')
        ->and($company->company_type)->toBe(CompanyType::Agency)
        ->and($company->website)->toBe('https://www.example-digital.test')
        ->and($jane->full_name)->toBe('Jane Example')
        ->and($jane->email->email)->toBe('jane@example-digital.test')
        ->and($jane->email->verification_status)->toBe(EmailVerificationStatus::Ok)
        ->and($jane->linkedin_url)->toBe('https://www.linkedin.com/in/jane-example-4b2a1')
        ->and($john->email->email)->toBe('john.sample@example-digital.test')
        ->and($company->contactEmail->email)->toBe('hello@example-digital.test')
        ->and($company->careersEmail->email)->toBe('careers@example-digital.test')
        ->and($run->refresh()->signals_found)->toBe(1)
        ->and($run->companies_created)->toBe(1);
});

it('writes a timeline entry for every step, in pipeline order', function () {
    fakeWorld();

    $company = runFromReedAd();

    $steps = $company->pipelineEvents()->orderBy('id')->pluck('step')->map(fn (PipelineStep $s) => $s->value)->unique()->values()->all();

    expect($steps)->toBe([
        'registry_search', 'registry_profile', 'registry_officers', 'classify', 'find_website', 'crawl',
        'build_email_candidates', 'verify_emails', 'find_linkedin', 'qualify',
    ])
        ->and($company->pipelineEvents()->where('outcome', PipelineOutcome::Failed)->exists())->toBeFalse()
        ->and($company->pipelineEvents()->orderBy('id')->first()->message)->toBe('Discovered from reed ad "Laravel Developer"');
});

it('only uses official APIs and the company\'s own site, and logs every call', function () {
    fakeWorld();

    runFromReedAd();

    $hosts = Http::recorded()->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_HOST))->unique()->sort()->values()->all();

    expect($hosts)->toBe([
        'api.company-information.service.gov.uk',
        'api.millionverifier.com',
        'api.search.brave.com',
        'www.example-digital.test',
        'www.reed.co.uk',
    ])
        ->and(ApiUsage::query()->distinct()->pluck('service')->map(fn (ExternalService $s) => $s->value)->sort()->values()->all())
        ->toBe(['brave', 'companies_house', 'crawler', 'millionverifier', 'reed'])
        ->and(ApiUsage::count())->toBe(Http::recorded()->count());
});

it('stops at each gate with the right reason', function (Closure $setup, RejectReason $reason) {
    fakeWorld(...$setup());

    $company = runFromReedAd();

    expect($company->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe($reason)
        ->and($company->pipelineEvents()->where('outcome', PipelineOutcome::Rejected)->exists())->toBeTrue();
})->with([
    'not on registry' => [fn () => [['*/search/companies*' => Http::response(jsonFixture('companies-house/search-none.json'))]], RejectReason::NotOnRegistry],
    'not active' => [fn () => [['*/search/companies*' => Http::response(jsonFixture('companies-house/search-dissolved.json'))]], RejectReason::NotActive],
    'wrong legal form' => [fn () => [['*company-information*/company/01234567' => Http::response([...jsonFixture('companies-house/profile.json'), 'type' => 'private-unlimited'])]], RejectReason::WrongLegalForm],
    'dormant' => [fn () => [['*company-information*/company/01234567' => Http::response(array_replace_recursive(jsonFixture('companies-house/profile.json'), ['accounts' => ['last_accounts' => ['type' => 'dormant']]]))]], RejectReason::Dormant],
    'recruiter' => [fn () => [['*company-information*/company/01234567' => Http::response([...jsonFixture('companies-house/profile.json'), 'sic_codes' => ['78109']])]], RejectReason::Recruiter],
    'offshore' => [fn () => [['*example-digital.test/services/laravel' => html('html/offshore-services.html')]], RejectReason::Offshore],
    'no website' => [fn () => [['*api.search.brave.com*' => fn (Request $r) => Http::response(jsonFixture('brave/website-no-match.json'))]], RejectReason::NoWebsite],
    'no valid email' => [fn () => [[], []], RejectReason::NoValidEmail],
    'no Laravel evidence' => [fn () => [[
        'https://www.reed.co.uk/*' => Http::response([...jsonFixture('reed/job.json'), 'jobTitle' => 'PHP Developer', 'jobDescription' => 'PHP and Symfony.']),
        'https://www.example-digital.test/' => html('html/no-links-home.html'),
    ], ['jane@example-digital.test' => 'ok']], RejectReason::NoLaravelEvidence],
]);

it('suppresses the lead as soon as its website domain is known', function () {
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'example-digital.test']);
    fakeWorld();

    $company = runFromReedAd();

    // The website step stores the domain and moves on; the gate then stops it before the first crawl request.
    expect($company->status)->toBe(LeadStatus::Suppressed)
        ->and($company->pipeline_step)->toBe(PipelineStep::Crawl)
        ->and(Http::recorded()->contains(fn ($pair) => str_contains($pair[0]->url(), 'example-digital.test/')))->toBeFalse()
        ->and(Http::recorded()->contains(fn ($pair) => str_contains($pair[0]->url(), 'millionverifier')))->toBeFalse();
});

// --- Retry step ------------------------------------------------------------------------

it('retries an errored company from the step it failed at', function () {
    fakeWorld();
    $company = Company::factory()->create([
        'name' => 'Example Digital Ltd', 'registration_number' => '01234567', 'website' => null, 'domain' => null,
        'status' => LeadStatus::Error, 'pipeline_step' => PipelineStep::RegistryProfile,
    ]);

    app(RetryPipelineStepAction::class)->run($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipelineEvents()->sole()->message)->toBe('Retry requested from registry_profile');
    Queue::assertPushed(FetchCompanyProfileJob::class, fn ($job) => $job->companyId === $company->id);
});

it('re-crawls from scratch when retrying the crawl', function () {
    fakeWorld();
    $company = runFromReedAd();
    $company->update(['status' => LeadStatus::Rejected, 'reject_reason' => RejectReason::NoValidEmail]);
    $pagesBefore = $company->crawledPages()->count();

    app(RetryPipelineStepAction::class)->run($company, PipelineStep::Crawl);
    drivePipeline();

    $robotsFetches = Http::recorded()->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/robots.txt'))->count();

    expect($pagesBefore)->toBe(6)
        ->and($company->refresh()->crawledPages()->count())->toBe(6)
        ->and($robotsFetches)->toBe(2) // cached rules were forgotten
        ->and($company->status)->toBe(LeadStatus::Qualified);
});

it('refuses to retry qualified or suppressed companies', function (LeadStatus $status) {
    $company = Company::factory()->create(['status' => $status]);

    expect(app(RetryPipelineStepAction::class)->canRetry($company))->toBeFalse();

    app(RetryPipelineStepAction::class)->run($company);
})->with([LeadStatus::Qualified, LeadStatus::Suppressed])->throws(InvalidArgumentException::class);
