<?php

declare(strict_types=1);

use App\Actions\IngestWebsitePlaceAction;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\SignalSource;
use App\Jobs\Sources\FetchGooglePlacesJob;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\Places\GooglePlacesService;
use App\Services\Support\DailyTarget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-21 10:00:00');
    config(['services.google_places.key' => 'test-places-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function runPlacesPage(FetchGooglePlacesJob $job): FetchGooglePlacesJob
{
    $job->withFakeQueueInteractions()->handle(app(GooglePlacesService::class), app(DailyTarget::class), app(IngestWebsitePlaceAction::class));

    return $job;
}

it('keeps only places with no website and enough reviews as website leads', function () {
    $run = ScrapeRun::factory()->create();
    Http::fake(['places.googleapis.com/*' => Http::response(jsonFixture('google-places/search-text.json'))]);

    runPlacesPage(new FetchGooglePlacesJob('Manchester', 'plumber', 10, 1, null, $run->id));

    $company = Company::query()->sole();

    expect($company->name)->toBe('Example Plumbing Co')
        ->and($company->lead_product)->toBe(LeadProduct::Website)
        ->and($company->status)->toBe(LeadStatus::Qualified)
        ->and($company->google_place_id)->toBe('ChIJExampleNoSite')
        ->and($company->phone)->toBe('0161 000 0001')
        ->and($company->review_count)->toBe(48)
        ->and($company->email_consent_required)->toBeTrue()
        ->and($company->website)->toBeNull()
        ->and($company->signals()->sole()->source)->toBe(SignalSource::GooglePlaces)
        ->and($run->refresh()->companies_created)->toBe(1)
        ->and($run->qualified_count)->toBe(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/v1/places:searchText')
        && $request['includedType'] === 'plumber'
        && str_contains((string) $request['textQuery'], 'Manchester')
        && $request->header('X-Goog-Api-Key')[0] === 'test-places-key');

    Queue::assertPushed(FetchGooglePlacesJob::class, fn ($job) => $job->page === 2 && $job->pageToken === 'NEXT_PAGE_TOKEN' && $job->take === 9);
});

it('is idempotent for the same place id', function () {
    Http::fake(['*' => Http::response(jsonFixture('google-places/search-text.json'))]);

    runPlacesPage(new FetchGooglePlacesJob('Manchester', 'plumber', 10));
    runPlacesPage(new FetchGooglePlacesJob('Manchester', 'plumber', 10));

    expect(Company::count())->toBe(1)->and(Signal::count())->toBe(1);
});

it('makes no call once the places daily cap is used', function () {
    Setting::put('source.places.per_day', 1);
    Signal::factory()->create(['source' => SignalSource::GooglePlaces]);
    Http::fake();

    runPlacesPage(new FetchGooglePlacesJob('Manchester', 'plumber', 5));

    Http::assertNothingSent();
});
