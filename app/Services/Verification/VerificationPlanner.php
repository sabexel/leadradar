<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use Illuminate\Support\Collection;

/**
 * Local: which address to spend the next verification credit on (docs/05 `verify_emails`).
 *
 * 1. Founder 1, then Founder 2: crawled addresses first, then generated guesses in try-order (id).
 *    A founder is done at their first `ok`.
 * 2. Then the company contact and careers inboxes.
 * Never re-verifies within `reverify_after_days`; once a domain answered `catch_all`, nothing else on that
 * domain is verified (every guess would be catch_all too — D68).
 */
final class VerificationPlanner
{
    public function next(Company $company): ?EmailAddress
    {
        $emails = $company->emailAddresses()->orderBy('id')->get();
        $catchAllDomains = $emails
            ->filter(fn (EmailAddress $e): bool => $e->verification_status === EmailVerificationStatus::CatchAll && $this->isFresh($e))
            ->map(fn (EmailAddress $e): string => $this->domainOf($e->email))
            ->unique()
            ->all();

        $worthChecking = fn (EmailAddress $e): bool => $this->needsVerification($e) && ! in_array($this->domainOf($e->email), $catchAllDomains, true);

        foreach ($company->founders()->get() as $founder) {
            $own = $this->forPerson($emails, $founder);

            if ($own->contains(fn (EmailAddress $e): bool => $e->verification_status === EmailVerificationStatus::Ok && $this->isFresh($e))) {
                continue;
            }

            $next = $own->first($worthChecking);

            if ($next !== null) {
                return $next;
            }
        }

        foreach ([$company->contact_email_id, $company->careers_email_id] as $inboxId) {
            $inbox = $inboxId === null ? null : $emails->firstWhere('id', $inboxId);

            if ($inbox !== null && $worthChecking($inbox)) {
                return $inbox;
            }
        }

        return null;
    }

    /**
     * A person's addresses in try-order: crawled (real) before generated (guesses), each by id.
     *
     * @param  Collection<int, EmailAddress>  $emails
     * @return Collection<int, EmailAddress>
     */
    public function forPerson(Collection $emails, Person $person): Collection
    {
        return $emails
            ->where('person_id', $person->id)
            ->sortBy(fn (EmailAddress $e): array => [$e->origin === EmailOrigin::Crawled ? 0 : 1, $e->id])
            ->values();
    }

    public function needsVerification(EmailAddress $email): bool
    {
        return $email->verification_status === EmailVerificationStatus::Unverified || ! $this->isFresh($email);
    }

    public function isFresh(EmailAddress $email): bool
    {
        $days = (int) config('services.millionverifier.reverify_after_days', 90);

        return $email->verified_at !== null && $email->verified_at->greaterThan(now()->subDays($days));
    }

    private function domainOf(string $email): string
    {
        return (string) substr((string) strrchr($email, '@'), 1);
    }
}
