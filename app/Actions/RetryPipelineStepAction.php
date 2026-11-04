<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Services\Crawler\RobotsTxtGuard;
use InvalidArgumentException;

/**
 * Local, sync: "Retry step" from the dashboard (docs/05). Puts an errored — or, by hand, a rejected — company
 * back into the pipeline at a step and advances it. Qualified and suppressed companies cannot be retried.
 */
final class RetryPipelineStepAction
{
    public function __construct(
        private readonly AdvanceLeadPipelineAction $advance,
        private readonly RobotsTxtGuard $robots,
    ) {}

    public function canRetry(Company $company): bool
    {
        return in_array($company->status, [LeadStatus::Error, LeadStatus::Rejected], true);
    }

    /**
     * @param  PipelineStep|null  $step  defaults to the step the company stopped at
     *
     * @throws InvalidArgumentException when the company cannot be retried, or the step is not a real step
     */
    public function run(Company $company, ?PipelineStep $step = null): Company
    {
        if (! $this->canRetry($company)) {
            throw new InvalidArgumentException("Company {$company->id} is {$company->status->value} and cannot be retried.");
        }

        $step ??= $company->pipeline_step;

        if ($step === PipelineStep::Done) {
            $step = PipelineStep::Qualify;
        }

        $this->resetStep($company, $step);

        $company->update([
            'status' => LeadStatus::Enriching,
            'reject_reason' => null,
            'pipeline_step' => $step,
        ]);

        $company->recordEvent($step, PipelineOutcome::Ok, "Retry requested from {$step->value}");

        $this->advance->run($company->refresh());

        return $company->refresh();
    }

    /**
     * Forget what a step remembers about earlier attempts, so the retry really runs it again.
     */
    private function resetStep(Company $company, PipelineStep $step): void
    {
        match ($step) {
            // Fresh crawl: previous pages (e.g. all skipped by a 5xx robots.txt) and the cached rules go.
            PipelineStep::Crawl => $this->resetCrawl($company),
            // Search again for founders that were looked up but not found.
            PipelineStep::FindLinkedIn => $company->people()->whereNull('linkedin_url')->update(['linkedin_checked_at' => null]),
            PipelineStep::FindWebsite => $company->update(['website' => null, 'domain' => null]),
            default => null,
        };
    }

    private function resetCrawl(Company $company): void
    {
        $company->crawledPages()->delete();

        if ($company->domain !== null) {
            $this->robots->forget($company->domain);
        }
    }
}
