<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCrawlResultAction;
use App\Actions\ApplyVerificationResultAction;
use App\Actions\BuildFounderEmailCandidatesAction;
use App\Actions\IngestAgencyAction;
use App\Actions\IngestJobAdAction;
use App\Actions\QualifyCompanyAction;
use App\DTOs\JobBoards\JobAdData;
use App\DTOs\Verification\EmailVerificationResult;
use App\Enums\CompanyType;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PageType;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Jobs\Enrichment\VerifyEmailJob;
use App\Jobs\Sources\FetchAgencySearchJob;
use App\Jobs\Sources\FetchPlacesAgenciesJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Signal;
use App\Models\Suppression;
use App\Services\Crawler\TechStackDetector;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Places\GooglePlacesService;
use App\Services\Search\AgencyResultSelector;
use App\Services\Search\BraveSearchService;
use App\Services\Search\WebsiteSelector;
use App\Services\Verification\MailDomainChecker;
use App\Services\Verification\MillionVerifierService;
use App\Services\Verification\VerificationPlanner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['services.brave.key' => 'test-brave-key']);
});

function runAgencySearch(string $country, string $city = 'Toronto'): void
{
    Http::fake(['api.search.brave.com/*' => Http::response(jsonFixture('brave/agency-search.json'))]);

    (new FetchAgencySearchJob('shopify agency', $city, $country))->withFakeQueueInteractions()->handle(
        app(BraveSearchService::class),
        app(AgencyResultSelector::class),
        app(IngestAgencyAction::class),
    );
}

/**
 * An agency lead that has been crawled: website known, a contact inbox, a stack.
 *
 * @param  array<string, mixed>  $attributes
 */
function crawledAgency(array $attributes = [], EmailVerificationStatus $inbox = EmailVerificationStatus::Ok): Company
{
    $company = Company::factory()->create([
        'lead_product' => LeadProduct::Agency,
        'company_type' => CompanyType::Agency,
        'country' => 'CA',
        'registration_number' => null,
        'registry' => null,
        'company_status' => null,
        'company_kind' => null,
        'accounts_category' => null,
        'incorporated_on' => null,
        'tech_stack' => ['built:wordpress', 'offers:wordpress', 'offers:shopify'],
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::Qualify,
        ...$attributes,
    ]);
    Signal::factory()->for($company)->create(['source' => SignalSource::AgencySearch]);
    $email = EmailAddress::factory()->for($company)->verified($inbox)->create([
        'email' => 'hello@'.$company->domain,
        'kind' => EmailKind::Contact,
        'origin' => EmailOrigin::Crawled,
    ]);
    $company->update(['contact_email_id' => $email->id]);

    return $company->refresh();
}

it('turns agency search results into Agency leads in the pipeline', function () {
    runAgencySearch('CA');

    $leads = Company::query()->orderBy('id')->get();

    expect($leads->pluck('domain')->all())->toBe(['maplepixel.example.ca', 'northerncommerce.example.com', 'lakesidedigital.example.com'])
        ->and($leads->every(fn (Company $c) => $c->lead_product === LeadProduct::Agency && $c->country === 'CA' && $c->location === 'Toronto'))->toBeTrue()
        ->and($leads->first()->company_type)->toBe(CompanyType::Agency)
        ->and($leads->first()->signals()->sole()->source)->toBe(SignalSource::AgencySearch)
        // Outside the UK there is no registry step: classify → known website → crawl.
        ->and($leads->first()->pipeline_step)->toBe(PipelineStep::Crawl);

    Queue::assertPushed(CrawlCompanyWebsiteJob::class, 3);
});

it('sends UK agencies to the free Companies House search first, and flags consent countries', function () {
    runAgencySearch('GB', 'Leeds');
    Queue::assertPushed(SearchCompaniesHouseJob::class, 2);

    runAgencySearch('DE', 'Berlin');
    // Same domains: nothing new (dedupe by domain across markets).
    expect(Company::count())->toBe(2);

    Company::query()->delete();
    runAgencySearch('DE', 'Berlin');
    expect(Company::query()->pluck('email_consent_required')->unique()->all())->toBe([true]);
});

it('stops at the daily cap and skips suppressed domains', function () {
    Setting::put('source.agency.per_day', 1);
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'maplepixel.example.ca']);

    runAgencySearch('CA');

    expect(Company::query()->pluck('domain')->all())->toBe(['northerncommerce.example.com']);
});

it('qualifies an agency with a verified inbox and a served stack — no Laravel evidence needed', function () {
    $company = crawledAgency();

    $score = app(QualifyCompanyAction::class)->run($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Qualified)
        ->and($score->breakdown['stack']['points'])->toBe(3.0)
        ->and($score->breakdown['company_fit']['points'])->toBe(1.5)
        ->and($score->breakdown['company_inboxes']['points'])->toBe(1.5)
        ->and($score->total)->toBe(6.0);
});

it('rejects an agency without a verified address, and one that builds nothing we serve', function () {
    $noEmail = crawledAgency([], EmailVerificationStatus::Invalid);
    app(QualifyCompanyAction::class)->run($noEmail);

    $noStack = crawledAgency(['tech_stack' => ['built:wix']]);
    app(QualifyCompanyAction::class)->run($noStack);

    expect($noEmail->refresh()->reject_reason)->toBe(RejectReason::NoValidEmail)
        ->and($noStack->refresh()->reject_reason)->toBe(RejectReason::BelowThreshold);
});

it('lets agency leads past the crawl without Laravel evidence, but stops self-declared recruiters', function () {
    $agency = crawledAgency(['pipeline_step' => PipelineStep::Crawl, 'laravel_evidence' => null]);
    app(ApplyCrawlResultAction::class)->finish($agency);
    expect($agency->refresh()->pipeline_step)->toBe(PipelineStep::BuildEmailCandidates);

    $recruiter = crawledAgency(['pipeline_step' => PipelineStep::Crawl, 'tech_stack' => ['flag:recruiter']]);
    app(ApplyCrawlResultAction::class)->finish($recruiter);
    expect($recruiter->refresh()->reject_reason)->toBe(RejectReason::Recruiter);
});

it('detects stacks and recruiters from page content, ignoring "no agencies" notices', function () {
    $detector = new TechStackDetector;
    $html = '<link href="/wp-content/themes/x.css"><script src="https://cdn.shopify.com/s.js"></script>';

    expect($detector->detect($html, 'We build WordPress and WooCommerce sites, and Shopify Plus stores.', ['laravel_session']))
        ->toEqual(['built:wordpress', 'built:shopify', 'built:laravel', 'offers:wordpress', 'offers:woocommerce', 'offers:shopify'])
        ->and($detector->detect('', "We're a specialist tech recruitment agency in Leeds.", [], PageType::Home))->toContain('flag:recruiter')
        ->and($detector->detect('', 'We are a recruitment agency.', [], PageType::Careers))->not->toContain('flag:recruiter')
        ->and($detector->detect('', 'We are a web agency. No recruitment agencies please.', [], PageType::Home))->not->toContain('flag:recruiter');
});

it('never guesses founder addresses outside the countries that allow it', function () {
    $company = Company::factory()->create(['country' => 'CA', 'lead_product' => LeadProduct::Agency, 'pipeline_step' => PipelineStep::BuildEmailCandidates]);
    Person::factory()->for($company)->create(['first_name' => 'Jane', 'last_name' => 'Example', 'position' => 1]);

    app(BuildFounderEmailCandidatesAction::class)->run($company);

    expect(EmailAddress::query()->where('origin', EmailOrigin::Generated)->count())->toBe(0)
        ->and($company->refresh()->pipeline_step)->toBe(PipelineStep::VerifyEmails);
});

it('ingests agency job ads abroad as Agency leads that go straight to the website search', function () {
    $ad = new JobAdData(
        source: SignalSource::Adzuna,
        externalId: 'us-1',
        title: 'Shopify Developer',
        employerName: 'Example Commerce Inc',
        employerExternalId: null,
        location: 'Austin, TX',
        description: 'Our agency needs a Shopify developer.',
        url: 'https://www.adzuna.com/details/1',
        externalUrl: null,
        postedAt: now()->subDay()->toImmutable(),
        raw: [],
    );

    app(IngestJobAdAction::class)->run($ad, null, LeadProduct::Agency, 'us');

    $company = Company::sole();
    expect($company->lead_product)->toBe(LeadProduct::Agency)
        ->and($company->country)->toBe('US')
        ->and($company->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipeline_step)->toBe(PipelineStep::FindWebsite);
});

it('stops a lead that cannot reach the threshold before any email check', function () {
    // An in-house team from a job ad, whose site offers nothing we serve: best case 0.75 + 1.5 + 1.5 < 4.5.
    $company = Company::factory()->create([
        'lead_product' => LeadProduct::Agency,
        'company_type' => CompanyType::DirectClient,
        'country' => 'US',
        'tech_stack' => ['built:wix'],
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::Crawl,
    ]);

    app(ApplyCrawlResultAction::class)->finish($company);

    expect($company->refresh()->reject_reason)->toBe(RejectReason::BelowThreshold)
        ->and($company->pipeline_step)->toBe(PipelineStep::Done)
        ->and(EmailAddress::count())->toBe(0);

    // The same company offering WordPress can still make it, so it continues.
    $agency = Company::factory()->create([
        'lead_product' => LeadProduct::Agency,
        'company_type' => CompanyType::Agency,
        'country' => 'US',
        'tech_stack' => ['offers:wordpress'],
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::Crawl,
    ]);
    app(ApplyCrawlResultAction::class)->finish($agency);

    expect($agency->refresh()->pipeline_step)->toBe(PipelineStep::BuildEmailCandidates);
});

// --- More addresses, fewer credits, real names (D120–D122) ------------------------------

function agencyForEmails(string $country = 'GB', string $domain = 'maplepixel.test'): Company
{
    $company = Company::factory()->create([
        'lead_product' => LeadProduct::Agency,
        'company_type' => CompanyType::Agency,
        'country' => $country,
        'domain' => $domain,
        'website' => 'https://'.$domain,
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::BuildEmailCandidates,
    ]);
    Signal::factory()->for($company)->create(['source' => SignalSource::AgencySearch]);

    return $company;
}

it('guesses info@ and hello@ for a UK/US agency that published no address, but nowhere else', function () {
    $uk = agencyForEmails('GB');
    app(BuildFounderEmailCandidatesAction::class)->run($uk);

    $ca = agencyForEmails('CA', 'northern.test');
    app(BuildFounderEmailCandidatesAction::class)->run($ca);

    expect($uk->emailAddresses()->orderBy('id')->pluck('email')->all())->toBe(['info@maplepixel.test', 'hello@maplepixel.test'])
        ->and($uk->emailAddresses()->first()->kind)->toBe(EmailKind::Contact)
        ->and($ca->emailAddresses()->count())->toBe(0);
});

it('verifies agency addresses cheapest-first and stops at the first that works', function () {
    $company = agencyForEmails('GB');
    $founder = Person::factory()->for($company)->create(['first_name' => 'Jane', 'last_name' => 'Example', 'position' => 1]);
    foreach (['jane@maplepixel.test', 'jane.example@maplepixel.test', 'jexample@maplepixel.test', 'janeexample@maplepixel.test'] as $email) {
        EmailAddress::factory()->forPerson($founder)->create(['email' => $email]);
    }
    $info = EmailAddress::factory()->for($company)->create(['email' => 'info@maplepixel.test', 'kind' => EmailKind::Contact, 'origin' => EmailOrigin::Generated]);
    $planner = app(VerificationPlanner::class);

    // Three founder guesses come back invalid → the fourth is never paid for; the inbox guess is next.
    foreach (range(1, 3) as $i) {
        $planner->next($company)->update(['verification_status' => EmailVerificationStatus::Invalid, 'verified_at' => now()]);
    }
    expect($planner->next($company)?->id)->toBe($info->id);

    $info->update(['verification_status' => EmailVerificationStatus::Ok, 'verified_at' => now()]);
    expect($planner->next($company))->toBeNull();
});

it('makes a working guessed inbox the contact inbox', function () {
    $company = agencyForEmails('US');
    $info = EmailAddress::factory()->for($company)->create(['email' => 'info@maplepixel.test', 'kind' => EmailKind::Contact, 'origin' => EmailOrigin::Generated]);

    app(ApplyVerificationResultAction::class)->apply($info, new EmailVerificationResult(
        email: 'info@maplepixel.test', status: EmailVerificationStatus::Ok, subresult: 'ok', quality: 'good', creditsLeft: 90, raw: ['result' => 'ok'],
    ));

    expect($company->refresh()->contact_email_id)->toBe($info->id);
});

it('spends no credit on a domain that cannot receive mail', function () {
    config(['services.millionverifier.mx_precheck' => true, 'services.millionverifier.key' => 'test-mv-key']);
    Http::fake();
    $company = agencyForEmails('GB');
    $company->update(['pipeline_step' => PipelineStep::VerifyEmails]);
    $info = EmailAddress::factory()->for($company)->create(['email' => 'info@maplepixel.test', 'kind' => EmailKind::Contact, 'origin' => EmailOrigin::Generated]);
    $hello = EmailAddress::factory()->for($company)->create(['email' => 'hello@maplepixel.test', 'kind' => EmailKind::Contact, 'origin' => EmailOrigin::Generated]);
    $noMail = new class extends MailDomainChecker
    {
        protected function lookup(string $domain): bool
        {
            return false;
        }
    };

    (new VerifyEmailJob($company->id, $info->id))->withFakeQueueInteractions()->handle(
        app(MillionVerifierService::class),
        app(VerificationPlanner::class),
        app(ApplyVerificationResultAction::class),
        app(AdvanceLeadPipelineAction::class),
        $noMail,
    );

    Http::assertNothingSent();
    expect($info->refresh()->verification_status)->toBe(EmailVerificationStatus::Invalid)
        ->and($info->verification_raw['source'])->toBe('dns')
        // The whole domain is a dead end: the second guess is never tried.
        ->and(app(VerificationPlanner::class)->next($company->refresh()))->toBeNull()
        ->and($hello->refresh()->verified_at)->toBeNull();
});

it('renames an agency-search lead to the site\'s own brand name', function () {
    $company = agencyForEmails('CA');
    $company->update(['name' => 'Web Design Toronto', 'pipeline_step' => PipelineStep::Crawl, 'tech_stack' => ['offers:wordpress']]);
    $company->crawledPages()->create(['url' => 'https://maplepixel.test/', 'http_status' => 200, 'fetched_at' => now(), 'page_type' => PageType::Home, 'extracted' => ['site_name' => 'Maple Pixel Studio']]);

    $generic = agencyForEmails('CA', 'other.test');
    $generic->update(['name' => 'Other Studio', 'pipeline_step' => PipelineStep::Crawl, 'tech_stack' => ['offers:wordpress']]);
    $generic->crawledPages()->create(['url' => 'https://other.test/', 'http_status' => 200, 'fetched_at' => now(), 'page_type' => PageType::Home, 'extracted' => ['site_name' => 'Web Design Agency in Toronto | Other']]);

    app(ApplyCrawlResultAction::class)->finish($company);
    app(ApplyCrawlResultAction::class)->finish($generic);

    expect($company->refresh()->name)->toBe('Maple Pixel Studio')
        ->and($generic->refresh()->name)->toBe('Other Studio');
});

it('adds agencies from Google Places with rating and review filters (D128)', function () {
    config(['services.google_places.key' => 'test-places-key']);
    Setting::put('source.places_agency.min_rating', 4.0);
    Setting::put('source.places_agency.min_reviews', 5);
    Http::fake(['places.googleapis.com/*' => Http::response(jsonFixture('google-places/agencies.json'))]);

    (new FetchPlacesAgenciesJob('shopify agency', 'Toronto', 'CA'))->withFakeQueueInteractions()->handle(
        app(GooglePlacesService::class),
        app(IngestAgencyAction::class),
        app(CompanyMatcher::class),
        app(WebsiteSelector::class),
    );

    Http::assertSent(fn ($request) => $request['minRating'] === 4.0 && $request['regionCode'] === 'CA' && $request['textQuery'] === 'shopify agency in Toronto');

    // 2 reviews (filter), no website, and a Facebook page are all left out.
    expect(Company::query()->pluck('domain')->all())->toBe(['maplepixel.example.ca'])
        ->and(Company::sole()->lead_product)->toBe(LeadProduct::Agency);
});

it('gives every market its share of the daily agency cap (D129)', function () {
    Setting::put('source.agency.per_day', 60);
    Setting::put('source.agency.countries', ['GB', 'US', 'CA', 'AU', 'IE', 'NZ', 'NL', 'BE', 'DK', 'SE', 'NO', 'FI', 'DE', 'AT', 'AE', 'XX', 'YY', 'ZZ', 'QQ', 'WW']);

    expect(app(IngestAgencyAction::class)->shareFor())->toBe(6);

    Setting::put('source.agency.per_day', 1);
    expect(app(IngestAgencyAction::class)->shareFor())->toBe(3);

    // With a share of 6, a seventh Canadian agency the same day is left for tomorrow.
    Company::factory()->count(6)->create(['country' => 'CA', 'lead_product' => LeadProduct::Agency])
        ->each(fn (Company $c) => Signal::factory()->for($c)->create(['source' => SignalSource::AgencySearch]));
    Setting::put('source.agency.per_day', 60);
    runAgencySearch('CA');

    expect(Company::query()->where('country', 'CA')->count())->toBe(6);
});
