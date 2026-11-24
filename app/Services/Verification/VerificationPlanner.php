<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadProduct;
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
 * Never re-verifies within `reverify_after_days`. Once a domain answered `catch_all` (every guess would be
 * catch_all too — D68) or showed it has no mail server (every guess would be invalid — D103), nothing else on
 * that domain is verified.
 */
final class VerificationPlanner
{
    /**
     * Agency leads (D120): guessed founder patterns tried per founder, and standard inboxes tried per lead.
     */
    public const MAX_FOUNDER_GUESSES = 3;

    public const MAX_ROLE_GUESSES = 2;

    /**
     * MillionVerifier subresults that describe the domain, not the mailbox.
     */
    private const NO_MAIL_SERVER_SUBRESULTS = ['dns_error'];

    public function next(Company $company): ?EmailAddress
    {
        $emails = $company->emailAddresses()->orderBy('id')->get();
        $deadEndDomains = $emails
            ->filter(fn (EmailAddress $e): bool => $this->isFresh($e) && ($e->verification_status === EmailVerificationStatus::CatchAll || $this->hasNoMailServer($e)))
            ->map(fn (EmailAddress $e): string => $this->domainOf($e->email))
            ->unique()
            ->all();

        $worthChecking = fn (EmailAddress $e): bool => $this->needsVerification($e) && ! in_array($this->domainOf($e->email), $deadEndDomains, true);

        $agency = in_array($company->lead_product, [LeadProduct::Agency, LeadProduct::Business], true);

        // Business leads (D133): one verification credit at most — phone / WhatsApp are their main route.
        if ($company->lead_product === LeadProduct::Business && $emails->contains(fn (EmailAddress $e): bool => $e->verified_at !== null)) {
            return null;
        }

        // Agency leads need one working address, not every one (D120): stop at the first verified address.
        if ($agency && $emails->contains(fn (EmailAddress $e): bool => $e->verification_status === EmailVerificationStatus::Ok && $this->isFresh($e))) {
            return null;
        }

        foreach ($company->founders()->get() as $founder) {
            $own = $this->forPerson($emails, $founder);

            if ($own->contains(fn (EmailAddress $e): bool => $e->verification_status === EmailVerificationStatus::Ok && $this->isFresh($e))) {
                continue;
            }

            // Agency leads: at most three guessed patterns per founder (the commonest come first).
            if ($agency && $own->filter(fn (EmailAddress $e): bool => $e->origin === EmailOrigin::Generated && $e->verified_at !== null)->count() >= self::MAX_FOUNDER_GUESSES) {
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

        if (! $agency) {
            return null;
        }

        $inboxIds = [$company->contact_email_id, $company->careers_email_id];

        // Agency leads (D116): then other addresses the agency published itself (a named person, sales@…), at most
        // two tried per lead.
        $published = fn (EmailAddress $e): bool => $e->origin === EmailOrigin::Crawled && $e->person_id === null && ! in_array($e->id, $inboxIds, true);

        if ($emails->filter(fn (EmailAddress $e): bool => $published($e) && $e->verified_at !== null)->count() < 2) {
            $next = $emails->first(fn (EmailAddress $e): bool => $published($e) && $worthChecking($e));

            if ($next !== null) {
                return $next;
            }
        }

        // Last: standard inboxes guessed for agencies that published none (info@, hello@ — GB/US only, D120),
        // at most two tried.
        $roleGuess = fn (EmailAddress $e): bool => $e->origin === EmailOrigin::Generated && $e->person_id === null && $e->kind === EmailKind::Contact;

        if ($emails->filter(fn (EmailAddress $e): bool => $roleGuess($e) && $e->verified_at !== null)->count() < self::MAX_ROLE_GUESSES) {
            return $emails->first(fn (EmailAddress $e): bool => $roleGuess($e) && $worthChecking($e));
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

    private function hasNoMailServer(EmailAddress $email): bool
    {
        $subresult = is_array($email->verification_raw) ? ($email->verification_raw['subresult'] ?? null) : null;

        return $email->verification_status === EmailVerificationStatus::Invalid
            && is_string($subresult)
            && in_array(strtolower($subresult), self::NO_MAIL_SERVER_SUBRESULTS, true);
    }

    public function domainOf(string $email): string
    {
        return (string) substr((string) strrchr($email, '@'), 1);
    }
}
