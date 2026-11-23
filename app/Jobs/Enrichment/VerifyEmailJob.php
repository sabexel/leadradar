<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyVerificationResultAction;
use App\DTOs\Verification\EmailVerificationResult;
use App\Enums\EmailVerificationStatus;
use App\Enums\ExternalService;
use App\Enums\PipelineStep;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\EmailAddress;
use App\Services\Verification\MailDomainChecker;
use App\Services\Verification\MillionVerifierService;
use App\Services\Verification\VerificationPlanner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * verify_emails: ONE address per job (1 MillionVerifier credit). The pipeline picks the next one.
 */
final class VerifyEmailJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId, public int $emailAddressId)
    {
        $this->onQueue('verify');
    }

    public function uniqueId(): string
    {
        return "verify:{$this->emailAddressId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::MillionVerifier->value)];
    }

    protected function step(): PipelineStep
    {
        return PipelineStep::VerifyEmails;
    }

    public function handle(
        MillionVerifierService $verifier,
        VerificationPlanner $planner,
        ApplyVerificationResultAction $apply,
        AdvanceLeadPipelineAction $advance,
        ?MailDomainChecker $mailDomains = null,
    ): void {
        $mailDomains ??= app(MailDomainChecker::class);
        $email = EmailAddress::with('company')->find($this->emailAddressId);
        $company = $email?->company;

        if ($email === null || $company === null || ! $company->isAwaiting($this->step())) {
            return;
        }

        if ($this->stopIfSuppressed($company, $advance)) {
            return;
        }

        // Verified recently (e.g. a retry of this job) → no second credit.
        if ($planner->needsVerification($email) && ! $mailDomains->acceptsMail($planner->domainOf($email->email))) {
            // The domain can't receive mail at all: recorded like MillionVerifier's dns_error, so the planner
            // treats the whole domain as a dead end (D103) — and no credit is spent (D121).
            $apply->apply($email, new EmailVerificationResult(
                email: $email->email,
                status: EmailVerificationStatus::Invalid,
                subresult: 'dns_error',
                quality: null,
                creditsLeft: null,
                raw: ['source' => 'dns', 'subresult' => 'dns_error', 'reason' => 'no MX/A record'],
            ));
        } elseif ($planner->needsVerification($email)) {
            [$called, $result] = $this->withinServiceLimits(fn (): EmailVerificationResult => $verifier->verify($email->email));

            if (! $called || $result === null) {
                return;
            }

            $apply->apply($email, $result);
        }

        $advance->run($company->refresh());
    }
}
