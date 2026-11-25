<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Jobs\Enrichment\FindDecisionMakerJob;
use App\Models\Company;
use App\Services\Crawler\RobotsTxtGuard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Local, sync (D136): fills in contact details for agency and business leads found before those details existed —
 * rejected leads included, so every row shows what we know.
 * - Leads crawled before phone/WhatsApp capture (D131) are crawled again (free; up to `$recrawlLimit` a run). Their
 *   pipeline runs on from the crawl, so a lead can change status; nothing already verified is paid for again.
 * - Finished leads with no owner get one decision-maker search each (Brave; up to `$ownerLimit` a run).
 */
final class BackfillContactsAction
{
    public function __construct(
        private readonly AdvanceLeadPipelineAction $advance,
        private readonly ApplyLinkedInResultAction $linkedIn,
        private readonly RobotsTxtGuard $robots,
    ) {}

    /**
     * @return array{recrawled: int, owner_searches: int}
     */
    public function run(int $recrawlLimit = 50, int $ownerLimit = 20): array
    {
        $recrawled = 0;

        $this->finishedAgencyOrBusiness()
            ->whereHas('crawledPages')
            ->whereDoesntHave('crawledPages', fn ($q) => $q->where('extracted', 'like', '%"phones"%'))
            ->whereNull('phone')
            // Once per lead: a site that publishes no phone would otherwise be crawled every hour.
            ->whereDoesntHave('pipelineEvents', fn ($q) => $q->where('message', 'like', 'Crawled again to fill in%'))
            ->orderBy('id')
            ->limit($recrawlLimit)
            ->get()
            ->each(function (Company $company) use (&$recrawled): void {
                $company->crawledPages()->delete();

                if ($company->domain !== null) {
                    $this->robots->forget($company->domain);
                }

                $company->update(['status' => LeadStatus::Enriching, 'reject_reason' => null, 'pipeline_step' => PipelineStep::Crawl]);
                $company->recordEvent(PipelineStep::Crawl, PipelineOutcome::Ok, 'Crawled again to fill in phone / WhatsApp / brand (backfill)');
                $this->advance->run($company->refresh());
                $recrawled++;
            });

        $searches = 0;

        $this->finishedAgencyOrBusiness()
            ->whereDoesntHave('people')
            ->whereDoesntHave('pipelineEvents', fn ($q) => $q->where('message', 'like', 'Decision-maker search%'))
            ->orderByDesc('id')
            ->limit($ownerLimit)
            ->get()
            ->each(function (Company $company) use (&$searches): void {
                if ($this->linkedIn->needsDecisionMakerSearch($company)) {
                    FindDecisionMakerJob::dispatch($company->id);
                    $searches++;
                }
            });

        return ['recrawled' => $recrawled, 'owner_searches' => $searches];
    }

    /**
     * Agency/business leads the pipeline has finished with: qualified, or rejected for a reason that is about
     * contact or score — not dead sites, recruiters, offshore shops or suppressed companies.
     *
     * @return Builder<Company>
     */
    private function finishedAgencyOrBusiness()
    {
        return Company::query()
            ->whereIn('lead_product', [LeadProduct::Agency, LeadProduct::Business])
            ->where('pipeline_step', PipelineStep::Done)
            ->where(fn ($q) => $q->where('status', LeadStatus::Qualified)
                ->orWhere(fn ($q) => $q->where('status', LeadStatus::Rejected)
                    ->whereIn('reject_reason', [RejectReason::NoValidEmail, RejectReason::BelowThreshold])));
    }
}
