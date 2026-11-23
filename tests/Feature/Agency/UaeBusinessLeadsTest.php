<?php

declare(strict_types=1);

use App\Actions\IngestBusinessAction;
use App\Actions\QualifyCompanyAction;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PageType;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Jobs\Sources\FetchBusinessSearchJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Setting;
use App\Services\Crawler\TechStackDetector;
use App\Services\Search\AgencyResultSelector;
use App\Services\Search\BraveSearchService;
use App\Services\Search\BusinessSearchPlanner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['services.brave.key' => 'test-brave-key']);
});

it('finds UAE businesses with their own sites, searching Brave worldwide for the UAE', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(jsonFixture('brave/business-search.json'))]);

    (new FetchBusinessSearchJob('dental clinic', 'Dubai', 'AE'))->withFakeQueueInteractions()->handle(
        app(BraveSearchService::class),
        app(AgencyResultSelector::class),
        app(IngestBusinessAction::class),
    );

    Http::assertSent(fn ($request) => $request['country'] === 'ALL' && $request['q'] === 'dental clinic Dubai');

    $leads = Company::query()->orderBy('id')->get();
    expect($leads->pluck('domain')->all())->toBe(['smileexample.ae', 'brightteeth.example.com'])
        ->and($leads->every(fn (Company $c) => $c->lead_product === LeadProduct::Business && $c->country === 'AE' && $c->location === 'Dubai'))->toBeTrue()
        ->and($leads->first()->signals()->sole()->source)->toBe(SignalSource::BusinessSearch)
        ->and($leads->first()->pipeline_step)->toBe(PipelineStep::Crawl);

    Queue::assertPushed(CrawlCompanyWebsiteJob::class, 2);
});

it('plans business searches across UAE cities and categories', function () {
    config(['leadradar.business_markets' => ['AE' => ['cities' => ['Dubai', 'Sharjah'], 'categories' => ['gym', 'spa']]]]);
    Setting::put('source.business.countries', ['AE']);
    Setting::put('source.business.queries_per_day', 3);
    Setting::put('source.business.cursor', 0);

    $batch = app(BusinessSearchPlanner::class)->nextBatch();

    expect(array_map(fn ($b) => "{$b['phrase']} {$b['city']}", $batch))->toBe(['gym Dubai', 'gym Sharjah', 'spa Dubai'])
        ->and(Setting::get('source.business.cursor'))->toBe(3);
});

it('audits a business home page for weaknesses', function () {
    $old = '<html><head><title>Clinic</title></head><body>© 2019 Smile Clinic</body></html>';
    $modern = '<html><head><meta name="viewport" content="width=device-width"><meta name="description" content="A modern dental clinic in Dubai Marina."></head><body>© 2026 Smile</body></html>';
    $detector = new TechStackDetector;

    expect($detector->detect($old, 'Smile Clinic', [], PageType::Home))->toContain('audit:no_viewport', 'audit:no_meta_description', 'audit:old_copyright')
        ->and($detector->detect($modern, 'Smile', [], PageType::Home))->not->toContain('audit:no_viewport')
        ->and($detector->detect($old, 'Smile Clinic', [], PageType::Contact))->not->toContain('audit:no_viewport');
});

it('qualifies a business with a weak site and a verified inbox, not one with a good site', function (array $stack, LeadStatus $expected) {
    $company = Company::factory()->create([
        'lead_product' => LeadProduct::Business, 'country' => 'AE', 'domain' => 'smileexample.ae', 'website' => 'https://smileexample.ae',
        'registration_number' => null, 'registry' => null, 'company_status' => null, 'accounts_category' => null,
        'tech_stack' => $stack, 'status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::Qualify,
    ]);
    $inbox = EmailAddress::factory()->for($company)->verified()->create(['email' => 'info@smileexample.ae', 'kind' => EmailKind::Contact, 'origin' => EmailOrigin::Crawled]);
    $company->update(['contact_email_id' => $inbox->id]);

    app(QualifyCompanyAction::class)->run($company->refresh());

    expect($company->refresh()->status)->toBe($expected);
})->with([
    'not mobile-ready, no https' => [['audit:no_viewport', 'audit:no_https'], LeadStatus::Qualified],
    'good site' => [['built:wordpress'], LeadStatus::Rejected],
]);
