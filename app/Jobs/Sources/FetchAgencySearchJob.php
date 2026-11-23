<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestAgencyAction;
use App\DTOs\Search\SearchResult;
use App\Enums\ExternalService;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\ScrapeRun;
use App\Services\Search\AgencyResultSelector;
use App\Services\Search\BraveSearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Collection;

/**
 * Discovery source (D113): ONE Brave web search such as "shopify agency Toronto" in that country's market. Agency
 * home pages from the results become Agency leads until the source's daily cap.
 */
final class FetchAgencySearchJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public function __construct(
        public string $phrase,
        public string $city,
        public string $country,
        public int $offset = 0,
        public ?int $scrapeRunId = null,
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "agency-search:{$this->country}:{$this->city}:{$this->phrase}:{$this->offset}:{$this->scrapeRunId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Brave->value)];
    }

    public function query(): string
    {
        return "{$this->phrase} {$this->city}";
    }

    public function handle(BraveSearchService $brave, AgencyResultSelector $selector, IngestAgencyAction $ingest): void
    {
        if ($ingest->remainingToday() === 0) {
            return;
        }

        [$called, $results] = $this->withinServiceLimits(
            fn (): Collection => $brave->searchAgencies($this->query(), $this->country, $this->offset),
        );

        if (! $called || ! $results instanceof Collection) {
            return;
        }

        /** @var Collection<int, SearchResult> $results */
        $run = $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId);

        foreach ($selector->select($results, $this->country) as $agency) {
            if ($ingest->remainingToday() === 0) {
                break;
            }

            $ingest->run($agency, $this->country, $this->city, $this->query(), $run);
        }
    }
}
