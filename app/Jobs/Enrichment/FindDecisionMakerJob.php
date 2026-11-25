<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyLinkedInResultAction;
use App\Actions\ApplyReverificationAction;
use App\Enums\ExternalService;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Company;
use App\Services\Search\BraveSearchService;
use App\Services\Search\DecisionMakerSelector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Collection;

/**
 * find_linkedin (D132): ONE Brave query for the owner / founder / CEO of a lead that has no registry founders.
 * Never requests linkedin.com (CLAUDE.md hard rule) — name, role and profile URL come from the search result.
 */
final class FindDecisionMakerJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('web');
    }

    public function uniqueId(): string
    {
        return "decision-maker:{$this->companyId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Brave->value)];
    }

    protected function step(): PipelineStep
    {
        return PipelineStep::FindLinkedIn;
    }

    public function handle(BraveSearchService $brave, DecisionMakerSelector $selector, ApplyLinkedInResultAction $linkedIn, AdvanceLeadPipelineAction $advance, ?ApplyReverificationAction $rescore = null): void
    {
        $company = Company::find($this->companyId);

        // In the pipeline at find_linkedin, or a finished lead (also rejected) being filled in afterwards (D136).
        $inPipeline = $company !== null && $company->isAwaiting($this->step());
        $finished = $company !== null && $company->isFinished() && $company->status !== LeadStatus::Suppressed;

        if ($company === null || (! $inPipeline && ! $finished) || ! $linkedIn->needsDecisionMakerSearch($company)) {
            return;
        }

        if ($this->stopIfSuppressed($company, $advance)) {
            return;
        }

        [$called, $results] = $this->withinServiceLimits(fn (): Collection => $brave->searchDecisionMakers($company->name, $company->country));

        if (! $called || ! $results instanceof Collection) {
            return;
        }

        $linkedIn->applyDecisionMaker($company, $selector->select($company->name, $results, $company->location));

        if ($inPipeline) {
            $advance->run($company->refresh());
        } else {
            // A known owner can change the score of a finished lead.
            ($rescore ?? app(ApplyReverificationAction::class))->rescoreIfFinished($company);
        }
    }
}
