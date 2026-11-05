<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Verification\EmailVerificationResult;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Models\EmailAddress;

/**
 * Local, sync: store a "Re-verify" result from the lead page (docs/07 §3), re-pick the founder's best address,
 * and rescore a finished lead so its status and score follow the new result (D92).
 */
final class ApplyReverificationAction
{
    public function __construct(
        private readonly ApplyVerificationResultAction $verification,
        private readonly QualifyCompanyAction $qualify,
    ) {}

    public function apply(EmailAddress $email, EmailVerificationResult $result): EmailAddress
    {
        $this->verification->apply($email, $result);

        $company = $email->company;
        $company->recordEvent(PipelineStep::VerifyEmails, PipelineOutcome::Ok, "Re-verified {$email->email}: {$result->status->value}", [
            'email_id' => $email->id,
            'status' => $result->status->value,
        ]);

        if ($email->person !== null) {
            $best = $this->verification->bestFor($company->emailAddresses()->orderBy('id')->get(), $email->person);
            $email->person->update(['email_id' => $best?->id]);
        }

        // Only leads the pipeline has finished with are rescored; one still enriching gets scored at its qualify step.
        $finished = $company->pipeline_step === PipelineStep::Done && (
            $company->status === LeadStatus::Qualified
            || ($company->status === LeadStatus::Rejected && in_array($company->reject_reason, [RejectReason::BelowThreshold, RejectReason::NoValidEmail], true))
        );

        if ($finished) {
            $this->qualify->run($company->refresh());
        }

        return $email->refresh();
    }
}
