<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestJobAdAction;
use App\DTOs\JobBoards\JobAdData;
use App\DTOs\JobBoards\JobSearchPage;
use App\Enums\ExternalService;
use App\Enums\LeadProduct;
use App\Enums\SignalSource;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\JobBoards\AdzunaService;
use App\Services\Support\DailyTarget;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One Adzuna search page. Adzuna has no detail endpoint, so each fresh, unknown ad is
 * ingested straight away (sync); then the next page is queued while the docs/05 conditions hold.
 */
final class FetchAdzunaJobsJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public function __construct(
        public string $keywords,
        public int $page = 1,
        public ?int $scrapeRunId = null,
        public string $country = 'gb',
        public string $product = 'laravel',
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "adzuna-search:{$this->country}:{$this->product}:{$this->keywords}:{$this->page}:{$this->scrapeRunId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Adzuna->value)];
    }

    public function handle(AdzunaService $adzuna, DailyTarget $target, IngestJobAdAction $ingest): void
    {
        if ($target->reached()) {
            return;
        }

        $maxAgeDays = (int) Setting::get('signal.max_age_days');

        [$called, $result] = $this->withinServiceLimits(
            fn (): JobSearchPage => $adzuna->search($this->keywords, $this->page, $maxAgeDays, AdzunaService::MAX_RESULTS_PER_PAGE, $this->country),
        );

        if (! $called || $result === null) {
            return;
        }

        $fresh = $result->ads->filter(fn (JobAdData $ad): bool => $ad->isWithinDays($maxAgeDays));

        $known = Signal::query()
            ->where('source', SignalSource::Adzuna)
            ->whereIn('external_id', $fresh->map(fn (JobAdData $ad): string => $ad->externalId)->all())
            ->pluck('external_id')
            ->all();

        $run = $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId);

        $fresh->reject(fn (JobAdData $ad): bool => in_array($ad->externalId, $known, true))
            ->each(function (JobAdData $ad) use ($ingest, $run): void {
                // Agency job ads (D117) have their own daily cap of new companies.
                if ($this->product === LeadProduct::Agency->value && $ingest->agencyJobsRemainingToday() === 0) {
                    return;
                }

                $ingest->run($ad, $run, LeadProduct::from($this->product), strtoupper($this->country));
            });

        // The next page re-checks the daily target before making its call.
        if ($this->shouldFetchNextPage($result, $maxAgeDays)) {
            self::dispatch($this->keywords, $this->page + 1, $this->scrapeRunId, $this->country, $this->product);
        }
    }

    private function shouldFetchNextPage(JobSearchPage $result, int $maxAgeDays): bool
    {
        if ($result->isEmpty() || ! $result->hasAdWithinDays($maxAgeDays)) {
            return false;
        }

        $maxPages = $this->product === LeadProduct::Agency->value ? 2 : (int) config('services.adzuna.max_pages', 10);

        if ($this->page >= $maxPages) {
            return false;
        }

        return $result->hasMoreAfter($this->page, AdzunaService::MAX_RESULTS_PER_PAGE);
    }
}
