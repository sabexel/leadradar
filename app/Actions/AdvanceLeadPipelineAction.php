<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\RejectReason;
use App\Models\Company;
use App\Services\Support\SuppressionList;

/**
 * Local state machine: decides the next pipeline step for a company and dispatches its job (docs/05).
 *
 * Built up step by step: P1.3 adds the suppression gate; P1.4+ wire in each step's job as it lands;
 * P1.12 completes the machine (all steps, events per transition, retry-from-step).
 */
final class AdvanceLeadPipelineAction
{
    private const FINISHED = [LeadStatus::Qualified, LeadStatus::Rejected, LeadStatus::Suppressed, LeadStatus::Error];

    public function __construct(private readonly SuppressionList $suppressions) {}

    public function run(Company $company): void
    {
        if (in_array($company->status, self::FINISHED, true)) {
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
        }
    }
}
