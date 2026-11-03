<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\DTOs\JobBoards\JobAdData;
use App\DTOs\JobBoards\JobSearchPage;
use App\Enums\ExternalService;
use App\Enums\SignalSource;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\JobBoards\ReedService;
use App\Services\Support\DailyTarget;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One Reed search page. Queues a detail job per new, fresh ad and the next page while
 * the docs/05 conditions hold (results non-empty, ads fresh, below target, more results).
 */
final class FetchReedJobsJob implements ShouldBeUnique, ShouldQueue
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
        return "reed-search:{$this->keywords}:{$this->page}:{$this->scrapeRunId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Reed->value)];
    }

    public function handle(ReedService $reed, DailyTarget $target): void
    {
        if ($target->reached()) {
            return;
        }

        $take = ReedService::MAX_RESULTS_PER_PAGE;

        [$called, $result] = $this->withinServiceLimits(
            fn (): JobSearchPage => $reed->search($this->keywords, ($this->page - 1) * $take, $take),
        );

        if (! $called || $result === null) {
            return;
        }

        $maxAgeDays = (int) Setting::get('signal.max_age_days');
        $fresh = $result->ads->filter(fn (JobAdData $ad): bool => $ad->isWithinDays($maxAgeDays));

        // Ads already stored don't need another detail call.
        $known = Signal::query()
            ->where('source', SignalSource::Reed)
            ->whereIn('external_id', $fresh->map(fn (JobAdData $ad): string => $ad->externalId)->all())
            ->pluck('external_id')
            ->all();

        $fresh->reject(fn (JobAdData $ad): bool => in_array($ad->externalId, $known, true))
            ->each(fn (JobAdData $ad) => FetchReedJobDetailJob::dispatch((int) $ad->externalId, $this->scrapeRunId));

        // The next page re-checks the daily target before making its call.
        if ($this->shouldFetchNextPage($result, $maxAgeDays, $take)) {
            self::dispatch($this->keywords, $this->page + 1, $this->scrapeRunId);
        }
    }

    private function shouldFetchNextPage(JobSearchPage $result, int $maxAgeDays, int $take): bool
    {
        if ($result->isEmpty() || ! $result->hasAdWithinDays($maxAgeDays)) {
            return false;
        }

        if ($this->page >= (int) config('services.reed.max_pages', 10)) {
            return false;
        }

        return $result->totalResults === null || $this->page * $take < $result->totalResults;
    }
}
