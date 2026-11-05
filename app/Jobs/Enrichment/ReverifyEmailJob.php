<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\ApplyReverificationAction;
use App\DTOs\Verification\EmailVerificationResult;
use App\Enums\ExternalService;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\EmailAddress;
use App\Services\Support\SuppressionList;
use App\Services\Verification\MillionVerifierService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * "Re-verify" button (docs/07 §3): ONE MillionVerifier call for one address, outside the pipeline.
 * Always spends a credit (the owner asked for it), but never on a suppressed company or address.
 */
final class ReverifyEmailJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public function __construct(public int $emailAddressId)
    {
        $this->onQueue('verify');
    }

    public function uniqueId(): string
    {
        return "reverify:{$this->emailAddressId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::MillionVerifier->value)];
    }

    public function handle(MillionVerifierService $verifier, SuppressionList $suppressions, ApplyReverificationAction $apply): void
    {
        $email = EmailAddress::with('company')->find($this->emailAddressId);

        if ($email === null || $suppressions->isSuppressed($email->company)) {
            return;
        }

        [$called, $result] = $this->withinServiceLimits(fn (): EmailVerificationResult => $verifier->verify($email->email));

        if ($called && $result !== null) {
            $apply->apply($email, $result);
        }
    }

    /**
     * A failed re-verify leaves the lead as it was; it only notes the failure on the timeline.
     */
    public function failed(?Throwable $exception): void
    {
        EmailAddress::with('company')->find($this->emailAddressId)?->company->recordEvent(
            PipelineStep::VerifyEmails,
            PipelineOutcome::Failed,
            'Re-verify failed: '.($exception?->getMessage() ?? 'job failed'),
            ['email_id' => $this->emailAddressId],
        );
    }
}
