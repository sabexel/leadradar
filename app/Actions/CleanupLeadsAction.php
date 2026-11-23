<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Models\Company;
use App\Models\PipelineEvent;

/**
 * Local, sync (D124): keeps the lead list clean without anyone opening it.
 * - "error" leads whose website is dead (no DNS, broken certificate, redirect loop) → rejected `no_website`;
 * - other "error" leads (timeouts) → retried from their step, at most twice in total;
 * - leads stuck mid-pipeline for hours (a lost job) → advanced again (unique jobs prevent doubles);
 * - agency-search leads still carrying an SEO title as their name → renamed to the site's brand.
 */
final class CleanupLeadsAction
{
    public const MAX_RETRIES = 2;

    public function __construct(
        private readonly RetryPipelineStepAction $retry,
        private readonly AdvanceLeadPipelineAction $advance,
        private readonly ApplyCrawlResultAction $crawl,
    ) {}

    /**
     * @return array{dead_sites: int, retried: int, gave_up: int, restarted: int, renamed: int}
     */
    public function run(int $stuckAfterHours = 6): array
    {
        $counts = ['dead_sites' => 0, 'retried' => 0, 'gave_up' => 0, 'restarted' => 0, 'renamed' => 0];

        Company::query()->where('status', LeadStatus::Error)->orderBy('id')->each(function (Company $company) use (&$counts): void {
            $failure = $company->pipelineEvents()->where('outcome', PipelineOutcome::Failed)->latest('id')->first();
            $message = (string) $failure?->message;

            if ($company->pipeline_step === PipelineStep::Crawl && CrawlCompanyWebsiteJob::isPermanentFailure($message)) {
                $company->update(['pipeline_step' => PipelineStep::Done]);
                $company->reject(RejectReason::NoWebsite, 'Website unreachable (cleanup): '.mb_substr($message, 0, 160));
                $counts['dead_sites']++;

                return;
            }

            $retries = $company->pipelineEvents()->where('message', 'like', 'Retry requested%')->count();

            if ($retries >= self::MAX_RETRIES) {
                $counts['gave_up']++;

                return;
            }

            $this->retry->run($company);
            $counts['retried']++;
        });

        Company::query()
            ->whereIn('status', [LeadStatus::Discovered, LeadStatus::Enriching])
            ->where('updated_at', '<', now()->subHours($stuckAfterHours))
            ->orderBy('id')
            ->each(function (Company $company) use (&$counts): void {
                $company->touch(); // so the next pass waits again before touching it
                $this->advance->run($company);
                $counts['restarted']++;
            });

        Company::query()
            ->where('lead_product', LeadProduct::Agency)
            ->whereHas('crawledPages')
            ->orderBy('id')
            ->each(function (Company $company) use (&$counts): void {
                $before = $company->name;
                $this->crawl->applyBrandName($company, $company->crawledPages()->get());
                $counts['renamed'] += $company->refresh()->name !== $before ? 1 : 0;
            });

        return $counts;
    }

    /**
     * For the timeline-free summary in tests and the command.
     */
    public static function lastFailure(Company $company): ?PipelineEvent
    {
        return $company->pipelineEvents()->where('outcome', PipelineOutcome::Failed)->latest('id')->first();
    }
}
