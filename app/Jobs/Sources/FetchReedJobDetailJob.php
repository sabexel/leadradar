<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestJobAdAction;
use App\DTOs\JobBoards\JobAdData;
use App\Enums\ExternalService;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\ScrapeRun;
use App\Services\JobBoards\ReedService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One Reed job detail → IngestJobAdAction (sync).
 */
final class FetchReedJobDetailJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public function __construct(
        public int $jobId,
        public ?int $scrapeRunId = null,
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "reed-job:{$this->jobId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Reed->value)];
    }

    public function handle(ReedService $reed, IngestJobAdAction $ingest): void
    {
        [$called, $ad] = $this->withinServiceLimits(fn (): ?JobAdData => $reed->getJob($this->jobId));

        if (! $called || $ad === null) {
            return;
        }

        $ingest->run($ad, $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId));
    }
}
