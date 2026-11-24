<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyLinkedInResultAction;
use App\Enums\DataSource;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Jobs\Enrichment\FindDecisionMakerJob;
use App\Models\Company;
use App\Models\Person;
use App\Services\Crawler\PhoneExtractor;
use App\Services\Search\BraveSearchService;
use App\Services\Search\DecisionMakerSelector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['services.brave.key' => 'test-brave-key']);
});

it('reads phone numbers from tel: links and WhatsApp from wa.me links only', function () {
    $found = (new PhoneExtractor)->extract([
        ['href' => 'tel:+971 4 123 4567', 'text' => 'Call us'],
        ['href' => 'tel:00971501234567', 'text' => ''],
        ['href' => 'tel:12', 'text' => 'too short'],
        ['href' => 'https://wa.me/971501234567?text=Hi', 'text' => 'WhatsApp'],
        ['href' => 'https://example.test/contact', 'text' => '+971 50 999 9999'],
    ]);

    expect($found['phones'])->toBe(['+97141234567', '+971501234567'])
        ->and($found['whatsapp'])->toBe('+971501234567');
});

it('finds the owner on LinkedIn through search for a lead without registry founders', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(jsonFixture('brave/decision-maker.json'))]);
    $company = Company::factory()->create([
        'name' => 'Smile Example Dental', 'country' => 'AE', 'lead_product' => LeadProduct::Business,
        'status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::FindLinkedIn,
    ]);

    $linkedIn = app(ApplyLinkedInResultAction::class);
    expect($linkedIn->needsDecisionMakerSearch($company))->toBeTrue();

    (new FindDecisionMakerJob($company->id))->withFakeQueueInteractions()->handle(
        app(BraveSearchService::class),
        app(DecisionMakerSelector::class),
        $linkedIn,
        app(AdvanceLeadPipelineAction::class),
    );

    // The company page and the receptionist are skipped; the founder is kept.
    $owner = $company->founders()->sole();
    expect($owner->first_name)->toBe('Omar')
        ->and($owner->last_name)->toBe('Example')
        ->and($owner->linkedin_url)->toBe('https://www.linkedin.com/in/omar-example-123')
        ->and($owner->source)->toBe(DataSource::WebSearch)
        ->and($linkedIn->needsDecisionMakerSearch($company->refresh()))->toBeFalse()
        ->and($company->pipeline_step)->not->toBe(PipelineStep::FindLinkedIn);

    Http::assertSentCount(1);
});

it('does not search for an owner when the registry already gave founders, or for Laravel leads', function () {
    $laravel = Company::factory()->create(['lead_product' => LeadProduct::Laravel, 'pipeline_step' => PipelineStep::FindLinkedIn]);
    $withFounder = Company::factory()->create(['lead_product' => LeadProduct::Agency, 'pipeline_step' => PipelineStep::FindLinkedIn]);
    Person::factory()->for($withFounder)->create(['position' => 1]);

    $linkedIn = app(ApplyLinkedInResultAction::class);

    expect($linkedIn->needsDecisionMakerSearch($laravel))->toBeFalse()
        ->and($linkedIn->needsDecisionMakerSearch($withFounder))->toBeFalse();
});
