<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyLinkedInResultAction;
use App\Actions\ApplyReverificationAction;
use App\Actions\QualifyCompanyAction;
use App\Enums\CompanyType;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PageType;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Jobs\Enrichment\FindDecisionMakerJob;
use App\Models\Company;
use App\Models\Signal;
use App\Models\User;
use App\Services\Search\BraveSearchService;
use App\Services\Search\DecisionMakerSelector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    config(['services.brave.key' => 'test-brave-key']);
});

it('shows why a lead was rejected or failed, in words', function () {
    $this->actingAs(User::factory()->create());
    $rejected = Company::factory()->rejected(RejectReason::BelowThreshold)->create(['qualified_at' => null]);
    $rejected->recordEvent(PipelineStep::Crawl, PipelineOutcome::Rejected, 'Best possible score 3.5 is below 4.5 — stopped before email checks');
    $qualified = Company::factory()->qualified()->create();
    $qualified->recordEvent(PipelineStep::Qualify, PipelineOutcome::Ok, 'Qualified with 7.5');

    $this->get(route('companies.index'))->assertInertia(fn (Assert $page) => $page
        ->where('companies.data', fn ($rows) => collect($rows)->firstWhere('id', $rejected->id)['statusNote'] === 'Best possible score 3.5 is below 4.5 — stopped before email checks'
            && collect($rows)->firstWhere('id', $qualified->id)['statusNote'] === null));
});

it('lets an agency that offers two served platforms qualify on a published phone (D137)', function () {
    $company = Company::factory()->create([
        'lead_product' => LeadProduct::Agency, 'company_type' => CompanyType::Agency, 'country' => 'SE',
        'registration_number' => null, 'registry' => null, 'company_status' => null, 'accounts_category' => null, 'incorporated_on' => null,
        'tech_stack' => ['built:wordpress', 'offers:wordpress', 'offers:woocommerce'], 'phone' => '+4681234567',
        'status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::Qualify,
    ]);
    Signal::factory()->for($company)->create(['source' => SignalSource::AgencySearch]);

    app(QualifyCompanyAction::class)->run($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Qualified);

    $oneStack = Company::factory()->create([...$company->only(['lead_product', 'company_type', 'country']), 'registration_number' => null, 'registry' => null, 'tech_stack' => ['offers:wordpress'], 'phone' => '+4681234568', 'pipeline_step' => PipelineStep::Qualify]);
    app(QualifyCompanyAction::class)->run($oneStack);

    expect($oneStack->refresh()->reject_reason)->toBe(RejectReason::NoValidEmail);
});

it('fills in the owner of an already-rejected lead and rescores it', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(jsonFixture('brave/decision-maker.json'))]);
    $company = Company::factory()->rejected(RejectReason::NoValidEmail)->create([
        'name' => 'Smile Example Dental', 'country' => 'AE', 'lead_product' => LeadProduct::Business, 'pipeline_step' => PipelineStep::Done,
    ]);

    (new FindDecisionMakerJob($company->id))->withFakeQueueInteractions()->handle(
        app(BraveSearchService::class),
        app(DecisionMakerSelector::class),
        app(ApplyLinkedInResultAction::class),
        app(AdvanceLeadPipelineAction::class),
        app(ApplyReverificationAction::class),
    );

    expect($company->founders()->sole()->first_name)->toBe('Omar')
        ->and($company->refresh()->pipeline_step)->toBe(PipelineStep::Done);
});

it('backfills older leads once: crawls them again and queues owner searches', function () {
    $old = Company::factory()->rejected(RejectReason::NoValidEmail)->create(['lead_product' => LeadProduct::Agency, 'country' => 'SE', 'pipeline_step' => PipelineStep::Done, 'phone' => null]);
    $old->crawledPages()->create(['url' => 'https://'.$old->domain.'/', 'http_status' => 200, 'fetched_at' => now(), 'page_type' => PageType::Home, 'extracted' => ['title' => 'Old']]);
    $recruiter = Company::factory()->rejected(RejectReason::Recruiter)->create(['lead_product' => LeadProduct::Agency, 'pipeline_step' => PipelineStep::Done]);

    $this->artisan('leadradar:backfill-contacts')
        ->expectsOutputToContain('Crawling again: 1 · owner searches queued: 0')
        ->assertSuccessful();

    expect($old->refresh()->status)->toBe(LeadStatus::Enriching)
        ->and($old->pipeline_step)->toBe(PipelineStep::Crawl)
        ->and($recruiter->refresh()->status)->toBe(LeadStatus::Rejected);
    Queue::assertPushed(CrawlCompanyWebsiteJob::class);

    // Finished again without a phone: not crawled a second time; its owner search is queued instead.
    $old->update(['status' => LeadStatus::Rejected, 'reject_reason' => RejectReason::NoValidEmail, 'pipeline_step' => PipelineStep::Done]);
    $old->crawledPages()->create(['url' => 'https://'.$old->domain.'/', 'http_status' => 200, 'fetched_at' => now(), 'page_type' => PageType::Home, 'extracted' => ['title' => 'Again']]);

    $this->artisan('leadradar:backfill-contacts')->expectsOutputToContain('Crawling again: 0 · owner searches queued: 1');
    Queue::assertPushed(FindDecisionMakerJob::class, fn ($job) => $job->companyId === $old->id);
});
