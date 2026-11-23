<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestBusinessAction;
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
 * Discovery source (D127): ONE Brave search such as "dental clinic Dubai". Each business's own site becomes a
 * Business lead until the source's daily cap (the same result filter as agency search: no directories, lists or
 * marketplaces, no foreign country domains).
 */
final class FetchBusinessSearchJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public function __construct(
        public string $category,
        public string $city,
        public string $country,
        public int $offset = 0,
        public ?int $scrapeRunId = null,
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "business-search:{$this->country}:{$this->city}:{$this->category}:{$this->offset}:{$this->scrapeRunId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Brave->value)];
    }

    public function handle(BraveSearchService $brave, AgencyResultSelector $selector, IngestBusinessAction $ingest): void
    {
        if ($ingest->remainingToday() === 0) {
            return;
        }

        $query = "{$this->category} {$this->city}";
        [$called, $results] = $this->withinServiceLimits(fn (): Collection => $brave->searchAgencies($query, $this->country, $this->offset));

        if (! $called || ! $results instanceof Collection) {
            return;
        }

        $run = $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId);

        foreach ($selector->select($results, $this->country) as $business) {
            if ($ingest->remainingToday() === 0) {
                break;
            }

            $ingest->run($business, $this->country, $this->city, $this->category, $run);
        }
    }
}
