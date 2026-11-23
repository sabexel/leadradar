<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestAgencyAction;
use App\DTOs\Places\PlaceSearchPage;
use App\Enums\ExternalService;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Places\GooglePlacesService;
use App\Services\Search\WebsiteSelector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Discovery source (D128): ONE Google Places Text Search for agencies in a city, filtered by minimum rating and
 * reviews (Settings). Each place's own website becomes an Agency lead through the same ingest as web search.
 */
final class FetchPlacesAgenciesJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public function __construct(
        public string $phrase,
        public string $city,
        public string $country,
        public ?int $scrapeRunId = null,
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "places-agencies:{$this->country}:{$this->city}:{$this->phrase}:{$this->scrapeRunId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::GooglePlaces->value)];
    }

    public function handle(GooglePlacesService $places, IngestAgencyAction $ingest, CompanyMatcher $matcher, WebsiteSelector $websites): void
    {
        if ($ingest->remainingToday() === 0) {
            return;
        }

        [$called, $page] = $this->withinServiceLimits(fn (): PlaceSearchPage => $places->searchAgencies(
            $this->phrase,
            $this->city,
            $this->country,
            (float) Setting::get('source.places_agency.min_rating'),
            (int) Setting::get('source.places_agency.min_reviews'),
        ));

        if (! $called || $page === null) {
            return;
        }

        $run = $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId);

        foreach ($page->places as $place) {
            $domain = $matcher->domainOf($place->website);

            if ($domain === null || $websites->isBlocked($domain) || $ingest->remainingToday() === 0) {
                continue;
            }

            $ingest->run(
                ['name' => $place->name, 'domain' => $domain, 'url' => 'https://'.$domain, 'title' => $place->name],
                $this->country,
                $this->city,
                "{$this->phrase} (Google Places, {$place->reviewCount} reviews)",
                $run,
            );
        }
    }
}
