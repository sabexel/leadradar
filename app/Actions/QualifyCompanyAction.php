<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Scoring\LeadScore;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Models\Company;
use App\Models\Setting;
use App\Services\Scoring\LeadScoringService;

/**
 * Local, sync (`qualify` step, and `leadradar:rescore`): gates + score → qualified | rejected(reason). docs/06.
 */
final class QualifyCompanyAction
{
    public function __construct(private readonly LeadScoringService $scoring) {}

    public function run(Company $company): LeadScore
    {
        $score = $this->scoring->score($company);
        $threshold = (float) Setting::get('qualify_threshold');

        $reason = $score->failedGate ?? ($score->total < $threshold ? RejectReason::BelowThreshold : null);

        $company->update([
            'score' => $score->total,
            'score_breakdown' => $score->toArray(),
            'status' => $reason === null ? LeadStatus::Qualified : LeadStatus::Rejected,
            'reject_reason' => $reason,
            // Keep the first qualification time on a rescore — it drives "qualified today" counts.
            'qualified_at' => $reason === null ? ($company->qualified_at ?? now()) : null,
            'pipeline_step' => PipelineStep::Done,
        ]);

        $company->recordEvent(
            PipelineStep::Qualify,
            $reason === null ? PipelineOutcome::Ok : PipelineOutcome::Rejected,
            $reason === null
                ? "Qualified with {$score->total}"
                : ($reason === RejectReason::BelowThreshold ? "Score {$score->total} below {$threshold}" : "Failed gate: {$reason->value}"),
            ['score' => $score->total, 'threshold' => $threshold, 'failed_gate' => $score->failedGate?->value],
        );

        return $score;
    }
}
