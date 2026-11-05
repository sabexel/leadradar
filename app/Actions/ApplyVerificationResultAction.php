<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Verification\EmailVerificationResult;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Verification\VerificationPlanner;
use Illuminate\Support\Collection;

/**
 * Local, sync: store one verification result, and finish the `verify_emails` step.
 */
final class ApplyVerificationResultAction
{
    public function __construct(private readonly VerificationPlanner $planner) {}

    public function apply(EmailAddress $email, EmailVerificationResult $result): EmailAddress
    {
        $email->update([
            'verification_status' => $result->status,
            'verification_raw' => $result->raw,
            'verified_at' => now(),
        ]);

        // The balance comes free with each result; the dashboard shows the last one (D86).
        if ($result->creditsLeft !== null) {
            Setting::put('millionverifier.credits', ['credits' => $result->creditsLeft, 'at' => now()->toIso8601String()]);
        }

        if ($result->status === EmailVerificationStatus::Ok && $email->person !== null) {
            $email->person->update(['email_id' => $email->id]);
        }

        return $email;
    }

    /**
     * Nothing left worth verifying: give each founder their best address and move on to LinkedIn.
     */
    public function finish(Company $company): Company
    {
        $emails = $company->emailAddresses()->orderBy('id')->get();
        $summary = [];

        foreach ($company->founders()->get() as $founder) {
            $best = $this->bestFor($emails, $founder);

            $founder->update(['email_id' => $best?->id]);
            $summary["founder_{$founder->position}"] = $best?->verification_status->value;
        }

        $company->update(['pipeline_step' => PipelineStep::FindLinkedIn]);
        $company->recordEvent(PipelineStep::VerifyEmails, PipelineOutcome::Ok, 'Verification finished', [
            ...$summary,
            'verified' => $emails->whereNotNull('verified_at')->count(),
            'ok' => $emails->where('verification_status', EmailVerificationStatus::Ok)->count(),
        ]);

        return $company;
    }

    /**
     * The founder's best address so far: verified before catch-all before unknown, crawled before generated.
     *
     * @param  Collection<int, EmailAddress>  $emails  all of the company's addresses
     */
    public function bestFor(Collection $emails, Person $founder): ?EmailAddress
    {
        return $this->planner->forPerson($emails, $founder)
            ->filter(fn (EmailAddress $e): bool => $this->rank($e) !== null)
            ->sortBy(fn (EmailAddress $e): array => [$this->rank($e), $e->origin === EmailOrigin::Crawled ? 0 : 1, $e->id])
            ->first();
    }

    /**
     * Best (0) to worst for a founder's address; null = never pick it. Invalid, disposable and error results
     * are never picked, nor are unverified guesses (they are not an address — an unverified crawled one is).
     */
    private function rank(EmailAddress $email): ?int
    {
        return match ($email->verification_status) {
            EmailVerificationStatus::Ok => 0,
            EmailVerificationStatus::CatchAll => 1,
            EmailVerificationStatus::Unknown => 2,
            EmailVerificationStatus::Unverified => $email->origin === EmailOrigin::Generated ? null : 3,
            default => null,
        };
    }
}
