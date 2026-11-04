<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Jobs\Enrichment\FetchCompanyOfficersJob;
use App\Jobs\Enrichment\FetchCompanyProfileJob;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Models\Company;
use App\Services\Support\SuppressionList;

/**
 * Local state machine: decides the next pipeline step for a company and dispatches its job (docs/05).
 *
 * Built up step by step (D37): suppression gate (P1.3), registry steps (P1.4); each later step's job
 * is added as it lands; P1.12 completes the machine (all steps, retry-from-step).
 */
final class AdvanceLeadPipelineAction
{
    public function __construct(private readonly SuppressionList $suppressions) {}

    public function run(Company $company): void
    {
        if ($company->isFinished()) {
            return;
        }

        // Suppressed companies get no further spend, at any step.
        $match = $this->suppressions->matchFor($company);

        if ($match !== null) {
            $company->update([
                'status' => LeadStatus::Suppressed,
                'reject_reason' => RejectReason::Suppressed,
            ]);

            $company->recordEvent($company->pipeline_step, PipelineOutcome::Rejected, 'Suppressed', [
                'type' => $match->type->value,
                'reason' => $match->reason->value,
            ]);

            return;
        }

        $job = $this->jobFor($company);

        if ($job === null) {
            return;
        }

        if ($company->status === LeadStatus::Discovered) {
            $company->update(['status' => LeadStatus::Enriching]);
        }

        dispatch($job);
    }

    private function jobFor(Company $company): ?object
    {
        // Only UK companies have a registry integration so far (NL/US come in P4.2).
        $registry = $company->country === 'GB';

        return match ($company->pipeline_step) {
            PipelineStep::RegistrySearch => $registry ? new SearchCompaniesHouseJob($company->id) : null,
            PipelineStep::RegistryProfile => $registry ? new FetchCompanyProfileJob($company->id) : null,
            PipelineStep::RegistryOfficers => $registry ? new FetchCompanyOfficersJob($company->id) : null,
            default => null, // later steps are wired in as they are built (P1.5+)
        };
    }
}
