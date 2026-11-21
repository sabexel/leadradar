<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestRegistryCompanyAction;
use App\DTOs\CompaniesHouse\RegistrySearchPage;
use App\Enums\ExternalService;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Services\CompaniesHouse\CompaniesHouseService;
use App\Services\Support\DailyTarget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Discovery source (D106): ONE Companies House advanced-search call for one SIC code in one location. New
 * companies go into the pipeline up to this pair's share of the source's daily cap; the position is remembered
 * per SIC + location so the next day continues where this one stopped, and wraps to the start once the list is
 * exhausted.
 */
final class FetchCompaniesHouseSicJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public const PAGE_SIZE = 50;

    public const MAX_PAGES_PER_RUN = 5;

    /**
     * @param  int  $take  most new companies this SIC + location may add today (its share of the daily cap)
     */
    public function __construct(
        public string $sicCode,
        public string $location,
        public int $take,
        public int $page = 1,
        public ?int $scrapeRunId = null,
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "ch-sic:{$this->sicCode}:{$this->location}:{$this->page}:{$this->scrapeRunId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::CompaniesHouse->value)];
    }

    public static function cursorKey(string $sicCode, string $location): string
    {
        return 'source.ch_sic.cursor.'.$sicCode.'|'.mb_strtolower(trim($location));
    }

    public function handle(CompaniesHouseService $companiesHouse, DailyTarget $target, IngestRegistryCompanyAction $ingest): void
    {
        if ($this->take < 1 || $target->reached() || $ingest->remainingToday() === 0) {
            return;
        }

        $key = self::cursorKey($this->sicCode, $this->location);
        $start = max(0, (int) Setting::get($key, 0));
        $minAge = max(0, (int) Setting::get('source.ch_sic.min_age_years'));
        $incorporatedTo = $minAge === 0 ? null : CarbonImmutable::now('Europe/London')->subYears($minAge)->startOfDay();

        [$called, $page] = $this->withinServiceLimits(
            fn (): RegistrySearchPage => $companiesHouse->advancedSearch($this->sicCode, $this->location, $start, self::PAGE_SIZE, $incorporatedTo),
        );

        if (! $called || $page === null) {
            return;
        }

        $run = $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId);
        // Every company looked at moves the position on, also the skipped ones (known, suppressed, wrong form).
        $consumed = 0;
        $created = 0;

        foreach ($page->companies as $company) {
            if ($created >= $this->take || $ingest->remainingToday() === 0) {
                break;
            }

            $created += $ingest->run($company, $this->sicCode, $this->location, $run) ? 1 : 0;
            $consumed++;
        }

        $next = $start + $consumed;
        $exhausted = $page->companies->isEmpty() || ($consumed === $page->companies->count() && $next >= $page->hits);

        Setting::put($key, $exhausted ? 0 : $next);

        $left = $this->take - $created;

        if (! $exhausted && $left > 0 && $consumed === $page->companies->count() && $this->page < self::MAX_PAGES_PER_RUN && $ingest->remainingToday() > 0) {
            self::dispatch($this->sicCode, $this->location, $left, $this->page + 1, $this->scrapeRunId);
        }
    }
}
