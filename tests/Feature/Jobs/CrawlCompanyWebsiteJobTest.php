<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCrawlResultAction;
use App\Enums\CompanyType;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Exceptions\ExternalServiceException;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Models\Company;
use App\Models\CrawledPage;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\Crawler\CrawlPlanner;
use App\Services\Crawler\RobotsTxtGuard;
use App\Services\Crawler\WebsiteCrawlerService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const SITE = 'https://www.example-digital.test';

beforeEach(function () {
    Queue::fake();
    Sleep::fake(); // no real waits between HTTP retries
});

function pageResponse(string $fixture, array $headers = []): PromiseInterface
{
    return Http::response((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/{$fixture}"), 200, ['Content-Type' => 'text/html; charset=utf-8', ...$headers]);
}

/**
 * @param  array<string, mixed>  $overrides  URL → response, merged over the default example site
 */
function fakeSite(array $overrides = [], string $robots = 'robots/allow.txt'): void
{
    $stubs = array_merge([
        SITE.'/robots.txt' => Http::response((string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/{$robots}"), 200, ['Content-Type' => 'text/plain']),
        SITE.'/' => pageResponse('html/home.html', ['Set-Cookie' => 'laravel_session=abc; path=/; httponly']),
        SITE.'/contact' => pageResponse('html/contact.html'),
        SITE.'/about/' => pageResponse('html/about.html'),
        SITE.'/careers' => pageResponse('html/careers.html'),
        SITE.'/services/laravel' => pageResponse('html/services.html'),
    ], $overrides); // overrides replace defaults in place

    $stubs['*'] = Http::response('Not found', 404, ['Content-Type' => 'text/html']);

    Http::fake($stubs);
}

function companyToCrawl(array $attributes = []): Company
{
    $company = Company::factory()->create([
        'name' => 'Example Digital Ltd',
        'legal_name' => 'EXAMPLE DIGITAL LTD',
        'website' => SITE,
        'domain' => 'example-digital.test',
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::Crawl,
        'company_type' => CompanyType::Unknown,
        'sic_codes' => ['62012'],
        'laravel_evidence' => null,
        ...$attributes,
    ]);

    Person::factory()->for($company)->position(1)->create(['first_name' => 'Jane', 'last_name' => 'Example', 'country_of_residence' => 'England']);
    Person::factory()->for($company)->position(2)->create(['first_name' => 'John', 'last_name' => 'Sample', 'country_of_residence' => 'England']);

    return $company;
}

function runCrawlJob(CrawlCompanyWebsiteJob $queued): CrawlCompanyWebsiteJob
{
    $job = (new CrawlCompanyWebsiteJob($queued->companyId, $queued->url))->withFakeQueueInteractions();
    $job->handle(app(WebsiteCrawlerService::class), app(RobotsTxtGuard::class), app(CrawlPlanner::class), app(ApplyCrawlResultAction::class), app(AdvanceLeadPipelineAction::class));
    test()->travel(10)->seconds();

    return $job;
}

/**
 * Start the crawl and run every queued crawl job (re-running released ones) until none are left.
 *
 * @return list<string> URLs in the order they were handled
 */
function crawlAll(Company $company): array
{
    app(AdvanceLeadPipelineAction::class)->run($company);

    $handled = [];
    $seen = [];

    for ($i = 0; $i < 30; $i++) {
        $next = collect(Queue::pushed(CrawlCompanyWebsiteJob::class))->first(fn ($job) => ! in_array(spl_object_id($job), $seen, true));

        if ($next === null) {
            break;
        }

        $seen[] = spl_object_id($next);

        do {
            $run = runCrawlJob($next);
        } while ($run->job->isReleased() && count($handled) < 30 && ($handled[] = 'released:'.$next->url));

        $handled[] = $next->url;
    }

    return array_values(array_filter($handled, fn (string $u) => ! str_starts_with($u, 'released:')));
}

function requestedUrls(): array
{
    return Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
}

it('crawls robots.txt, then home, then the best candidate pages, politely and as LeadRadarBot', function () {
    fakeSite();
    $company = companyToCrawl();

    $handled = crawlAll($company);

    expect($handled)->toBe([
        SITE.'/',
        SITE.'/contact',
        SITE.'/about/',
        SITE.'/careers',
        SITE.'/services/laravel',
        SITE.'/case-studies/booking-platform', // found on the services page (404 here → recorded, not fatal)
    ])
        ->and(requestedUrls()[0])->toBe(SITE.'/robots.txt')
        ->and(count(requestedUrls()))->toBe(7); // robots + 6 pages = page cap

    Http::assertSent(fn (Request $r) => str_starts_with($r->header('User-Agent')[0], 'LeadRadarBot/1.0 (+https://webwayout.co.uk/bot)'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'twitter.com') || str_contains($r->url(), 'sample-partner.test') || str_contains($r->url(), '.pdf'));
});

it('stores emails with provenance, links them to founders and fills the company inboxes', function () {
    fakeSite();
    $company = companyToCrawl();

    crawlAll($company);
    $company->refresh();

    $emails = $company->emailAddresses()->get()->keyBy('email');
    $jane = $company->people()->where('first_name', 'Jane')->sole();
    $john = $company->people()->where('first_name', 'John')->sole();

    expect($emails->keys()->sort()->values()->all())->toBe([
        'accounts@mail.example-digital.test',
        'careers@example-digital.test',
        'hello@example-digital.test',
        'jane@example-digital.test',
        'john.sample@example-digital.test',
    ])
        ->and($emails->every(fn ($e) => $e->origin === EmailOrigin::Crawled && $e->source_url !== null))->toBeTrue()
        ->and($emails['hello@example-digital.test']->source_url)->toBe(SITE.'/')
        ->and($emails['jane@example-digital.test']->kind)->toBe(EmailKind::Person)
        ->and($emails['jane@example-digital.test']->person_id)->toBe($jane->id)
        ->and($jane->email_id)->toBe($emails['jane@example-digital.test']->id)
        ->and($john->email_id)->toBe($emails['john.sample@example-digital.test']->id)
        ->and($company->contactEmail->email)->toBe('hello@example-digital.test')
        ->and($company->careersEmail->email)->toBe('careers@example-digital.test');
});

it('records Laravel evidence, stores facts but never HTML, and finishes the step', function () {
    fakeSite();
    $company = companyToCrawl();

    crawlAll($company);
    $company->refresh();

    $evidence = collect($company->laravel_evidence)->map(fn ($s) => $s['type'].':'.$s['weight'])->all();
    $pages = $company->crawledPages()->get();

    expect($evidence)->toEqualCanonicalizing([
        'laravel_mention:medium',  // home page
        'laravel_partner:strong',
        'runs_laravel:medium',
        'livewire:medium',
        'framework_list:weak',     // about page lists it among other frameworks
        'laravel_mention:strong',  // services page
        'laravel_mention:strong',  // careers page (different detail → kept separately)
    ])
        ->and(collect($company->laravel_evidence)->every(fn ($s) => isset($s['url'])))->toBeTrue()
        ->and($pages)->toHaveCount(6)
        ->and($pages->firstWhere('url', SITE.'/')->http_status)->toBe(200)
        ->and($pages->firstWhere('url', SITE.'/')->content_hash)->toHaveLength(64)
        ->and($pages->firstWhere('url', SITE.'/case-studies/booking-platform')->http_status)->toBe(404)
        ->and($pages->every(fn (CrawledPage $p) => ! str_contains(json_encode($p->extracted), '<')))->toBeTrue()
        ->and($company->company_type)->toBe(CompanyType::Agency)
        ->and($company->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipeline_step)->toBe(PipelineStep::BuildEmailCandidates)
        ->and($company->pipelineEvents()->where('step', PipelineStep::Crawl)->where('message', 'Crawled 5 page(s)')->exists())->toBeTrue();
});

it('respects the page cap setting', function () {
    Setting::put('cap.crawler.pages_per_company', 2);
    fakeSite();

    expect(crawlAll(companyToCrawl()))->toBe([SITE.'/', SITE.'/contact']);
});

it('skips the whole site when robots.txt disallows the home page', function () {
    fakeSite(robots: 'robots/block-all.txt');
    $company = companyToCrawl();

    crawlAll($company);
    $company->refresh();

    expect(requestedUrls())->toBe([SITE.'/robots.txt'])
        ->and($company->crawledPages()->sole()->extracted['skipped'])->toBe('robots')
        ->and($company->pipelineEvents()->where('step', PipelineStep::Crawl)->where('outcome', PipelineOutcome::Skipped)->sole()->message)->toContain('robots.txt')
        ->and($company->pipeline_step)->toBe(PipelineStep::BuildEmailCandidates);
});

it('obeys rules addressed to LeadRadarBot and its Crawl-delay', function () {
    fakeSite(robots: 'robots/leadradar-specific.txt');
    $company = companyToCrawl();

    crawlAll($company);

    expect(requestedUrls())->not->toContain(SITE.'/careers')
        ->and($company->crawledPages()->where('url', SITE.'/careers')->sole()->extracted['skipped'])->toBe('robots')
        ->and(app(RobotsTxtGuard::class)->cached('example-digital.test')?->crawlDelay)->toBe(5);
});

it('waits between requests to the same domain', function () {
    fakeSite();
    $company = companyToCrawl();
    app(RobotsTxtGuard::class)->remember('example-digital.test', app(RobotsTxtGuard::class)->parse(''));
    app(RobotsTxtGuard::class)->markRequested('example-digital.test');

    $job = (new CrawlCompanyWebsiteJob($company->id, SITE.'/'))->withFakeQueueInteractions();
    $job->handle(app(WebsiteCrawlerService::class), app(RobotsTxtGuard::class), app(CrawlPlanner::class), app(ApplyCrawlResultAction::class), app(AdvanceLeadPipelineAction::class));

    expect($job->job->isReleased())->toBeTrue();
    Http::assertNothingSent();
});

it('rejects the company as offshore when the site gives it away', function () {
    fakeSite([SITE.'/services/laravel' => pageResponse('html/offshore-services.html')]);
    $company = companyToCrawl(['name' => 'Sample Code Labs Ltd', 'legal_name' => 'SAMPLE CODE LABS LTD']);

    crawlAll($company);
    $company->refresh();

    expect($company->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::Offshore)
        ->and(collect($company->offshore_evidence)->pluck('type')->all())->toContain('hourly_rate_pricing');
});

it('falls back to sitemap.xml when the home page links to nothing useful', function () {
    fakeSite([
        SITE.'/' => pageResponse('html/no-links-home.html'),
        SITE.'/sitemap.xml' => Http::response((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/html/sitemap.xml'), 200, ['Content-Type' => 'application/xml']),
        SITE.'/contact-us' => pageResponse('html/contact.html'),
    ]);
    $company = companyToCrawl();

    expect(crawlAll($company))->toBe([SITE.'/', SITE.'/sitemap.xml', SITE.'/contact-us'])
        ->and($company->refresh()->emailAddresses()->where('email', 'careers@example-digital.test')->exists())->toBeTrue()
        ->and(collect($company->laravel_evidence)->pluck('type')->all())->toContain('inertia');
});

it('records an unreachable inner page and carries on', function () {
    fakeSite([SITE.'/contact' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
    $company = companyToCrawl();

    $handled = crawlAll($company);

    expect($handled)->toContain(SITE.'/about/')
        ->and($company->crawledPages()->where('url', SITE.'/contact')->sole()->extracted['skipped'])->toBe('unreachable');
});

it('lets an unreachable home page fail the job (retry, then error)', function () {
    fakeSite([SITE.'/' => fn () => throw new ConnectionException('cURL error 7: refused')]);
    $company = companyToCrawl();
    app(RobotsTxtGuard::class)->remember('example-digital.test', app(RobotsTxtGuard::class)->parse(''));

    $job = (new CrawlCompanyWebsiteJob($company->id, SITE.'/'))->withFakeQueueInteractions();

    expect(fn () => $job->handle(app(WebsiteCrawlerService::class), app(RobotsTxtGuard::class), app(CrawlPlanner::class), app(ApplyCrawlResultAction::class), app(AdvanceLeadPipelineAction::class)))
        ->toThrow(ExternalServiceException::class);
});

it('re-classifies a direct client using the website and its ads', function () {
    fakeSite([
        SITE.'/' => Http::response('<html><body><h1>Sample Schools Software</h1><p>Our SaaS product helps 2,000 schools.</p><a href="/careers">Careers</a></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $company = companyToCrawl(['name' => 'Sample Schools Software Ltd', 'legal_name' => 'SAMPLE SCHOOLS SOFTWARE LTD']);
    Signal::factory()->for($company)->create(['title' => 'Laravel Developer', 'description' => 'Join our in-house product team.']);

    crawlAll($company);

    expect($company->refresh()->company_type)->toBe(CompanyType::DirectClient)
        ->and($company->pipeline_step)->toBe(PipelineStep::BuildEmailCandidates);
});
