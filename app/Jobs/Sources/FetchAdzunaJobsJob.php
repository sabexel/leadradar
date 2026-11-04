<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestJobAdAction;
use App\DTOs\JobBoards\JobAdData;
use App\DTOs\JobBoards\JobSearchPage;
use App\Enums\ExternalService;
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
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "adzuna-search:{$this->keywords}:{$this->page}:{$this->scrapeRunId}";
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
            fn (): JobSearchPage => $adzuna->search($this->keywords, $this->page, $maxAgeDays),
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
            ->each(fn (JobAdData $ad) => $ingest->run($ad, $run));

        // The next page re-checks the daily target before making its call.
        if ($this->shouldFetchNextPage($result, $maxAgeDays)) {
            self::dispatch($this->keywords, $this->page + 1, $this->scrapeRunId);
        }
    }

    private function shouldFetchNextPage(JobSearchPage $result, int $maxAgeDays): bool
    {
        if ($result->isEmpty() || ! $result->hasAdWithinDays($maxAgeDays)) {
            return false;
        }

        if ($this->page >= (int) config('services.adzuna.max_pages', 10)) {
            return false;
        }

        return $result->hasMoreAfter($this->page, AdzunaService::MAX_RESULTS_PER_PAGE);
    }
}
