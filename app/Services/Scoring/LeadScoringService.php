<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\LeadScore;
use App\Enums\CompanyType;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Signal;
use App\Services\Support\SuppressionList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Local: hard gates + transparent 0–10 score, exactly per docs/06. Always computes the score, also for leads
 * that fail a gate (for analysis).
 */
final class LeadScoringService
{
    public const ALLOWED_FORMS = ['ltd', 'llp', 'plc'];

    /**
     * Companies House last-accounts types filed by small companies (D72).
     */
    public const SMALL_ACCOUNTS = ['micro-entity', 'small', 'total-exemption-small', 'total-exemption-full', 'unaudited-abridged', 'audited-abridged'];

    private const JOB_BOARDS = [SignalSource::Reed, SignalSource::Adzuna];

    public function __construct(private readonly SuppressionList $suppressions) {}

    public function score(Company $company): LeadScore
    {
        $company->loadMissing(['signals', 'emailAddresses', 'people', 'contactEmail', 'careersEmail']);

        $breakdown = [
            'laravel_evidence' => $this->laravelEvidence($company),
            'timing' => $this->timing($company),
            'company_fit' => $this->companyFit($company),
            'decision_maker_reach' => $this->reach($company),
            'company_inboxes' => $this->inboxes($company),
        ];

        $total = min(10.0, round(array_sum(array_column($breakdown, 'points')), 1));

        return new LeadScore($total, $breakdown, $this->failedGate($company));
    }

    /**
     * First failing hard gate, in docs/06 table order; null when all pass.
     */
    public function failedGate(Company $company): ?RejectReason
    {
        return match (true) {
            $company->country !== 'GB' || $company->registry !== 'companies_house' || $company->registration_number === null => RejectReason::NotOnRegistry,
            $company->company_status !== 'active' => RejectReason::NotActive,
            ! in_array($company->company_kind, self::ALLOWED_FORMS, true) => RejectReason::WrongLegalForm,
            $company->accounts_category === 'dormant' => RejectReason::Dormant,
            $company->company_type === CompanyType::Recruiter => RejectReason::Recruiter,
            $company->company_type === CompanyType::Offshore => RejectReason::Offshore,
            $company->website === null => RejectReason::NoWebsite,
            ! $this->isContactable($company) => RejectReason::NoValidEmail,
            ! $this->hasLaravelEvidence($company) => RejectReason::NoLaravelEvidence,
            $this->suppressions->isSuppressed($company) => RejectReason::Suppressed,
            default => null,
        };
    }

    /**
     * ≥ 1 email `ok`, or a founder email that is `catch_all` and was found on the site (not generated).
     */
    public function isContactable(Company $company): bool
    {
        if ($company->emailAddresses->contains(fn (EmailAddress $e): bool => $e->verification_status === EmailVerificationStatus::Ok)) {
            return true;
        }

        $founderIds = $this->founders($company)->pluck('id')->all();

        return $company->emailAddresses->contains(fn (EmailAddress $e): bool => in_array($e->person_id, $founderIds, true)
            && $e->verification_status === EmailVerificationStatus::CatchAll
            && $e->origin === EmailOrigin::Crawled);
    }

    /**
     * ≥ 1 strong signal, or a Laravel job ad from this company.
     */
    public function hasLaravelEvidence(Company $company): bool
    {
        return $this->laravelAds($company) !== [] || collect($company->laravel_evidence ?? [])->contains(fn (array $s): bool => ($s['weight'] ?? null) === 'strong');
    }

    /**
     * @return array{points: float, max: float, items: array<string, float>, days_old?: int|null}
     */
    private function laravelEvidence(Company $company): array
    {
        $types = collect($company->laravel_evidence ?? []);
        $strongMention = $types->contains(fn (array $s): bool => $s['type'] === 'laravel_mention' && ($s['weight'] ?? null) === 'strong');

        return $this->line(2.5, [
            'job_ad' => $this->laravelAds($company) !== [] ? 1.5 : 0.0,
            'site_mention' => $strongMention ? 1.0 : 0.0,
            'partner' => $types->contains(fn (array $s): bool => $s['type'] === 'laravel_partner') ? 1.0 : 0.0,
            'site_runs_laravel' => $types->contains(fn (array $s): bool => in_array($s['type'], ['runs_laravel', 'livewire', 'inertia'], true)) ? 0.5 : 0.0,
        ]);
    }

    /**
     * @return array{points: float, max: float, items: array<string, float>, days_old?: int|null}
     */
    private function timing(Company $company): array
    {
        $newest = collect($this->laravelAds($company))
            ->map(fn (Signal $s): ?CarbonImmutable => $s->posted_at === null ? null : CarbonImmutable::instance($s->posted_at))
            ->filter()
            ->max();

        $days = $newest === null ? null : $this->ageInDays($newest);

        $points = match (true) {
            $days === null => 0.0,
            $days <= 7 => 2.0,
            $days <= 14 => 1.5,
            $days <= 30 => 1.0,
            default => 0.0,
        };

        return [...$this->line(2.0, ['newest_ad' => $points]), 'days_old' => $days];
    }

    /**
     * @return array{points: float, max: float, items: array<string, float>, days_old?: int|null}
     */
    private function companyFit(Company $company): array
    {
        return $this->line(2.0, [
            'type' => match ($company->company_type) {
                CompanyType::Agency => 1.0,
                CompanyType::DirectClient => 0.5,
                default => 0.0,
            },
            'small_company' => in_array($company->accounts_category, self::SMALL_ACCOUNTS, true) ? 0.5 : 0.0,
            'established' => $company->incorporated_on !== null && $company->incorporated_on->lessThanOrEqualTo(now()->subYears(2)) ? 0.5 : 0.0,
        ]);
    }

    /**
     * @return array{points: float, max: float, items: array<string, float>, days_old?: int|null}
     */
    private function reach(Company $company): array
    {
        $founders = $this->founders($company);
        $first = $founders->firstWhere('position', 1);
        $second = $founders->firstWhere('position', 2);
        $firstEmail = $first === null ? null : $this->emailOf($company, $first);

        return $this->line(2.5, [
            'founder_1_email' => match ($firstEmail?->verification_status) {
                EmailVerificationStatus::Ok => 1.5,
                EmailVerificationStatus::CatchAll => 0.5,
                default => 0.0,
            },
            'founder_1_linkedin' => $first?->linkedin_url !== null ? 0.5 : 0.0,
            'founder_2_email' => $second !== null && $this->emailOf($company, $second) !== null ? 0.5 : 0.0,
        ]);
    }

    /**
     * @return array{points: float, max: float, items: array<string, float>, days_old?: int|null}
     */
    private function inboxes(Company $company): array
    {
        return $this->line(1.0, [
            'contact_ok' => $company->contactEmail?->verification_status === EmailVerificationStatus::Ok ? 0.5 : 0.0,
            'careers_found' => $company->careersEmail !== null ? 0.5 : 0.0,
        ]);
    }

    /**
     * Job-board signals linked to this company whose ad mentions Laravel.
     *
     * @return list<Signal>
     */
    private function laravelAds(Company $company): array
    {
        return array_values($company->signals
            ->filter(fn (Signal $s): bool => in_array($s->source, self::JOB_BOARDS, true)
                && preg_match('/\blaravel\b/i', $s->title.' '.($s->description ?? '')) === 1)
            ->all());
    }

    /**
     * @return Collection<int, Person>
     */
    private function founders(Company $company): Collection
    {
        return $company->people
            ->filter(fn (Person $p): bool => in_array($p->position, [1, 2], true) && $p->resigned_on === null)
            ->values();
    }

    private function emailOf(Company $company, Person $person): ?EmailAddress
    {
        return $person->email_id === null ? null : $company->emailAddresses->firstWhere('id', $person->email_id);
    }

    /**
     * Whole days since the ad was posted, on the London calendar (posted today = 0).
     */
    private function ageInDays(CarbonImmutable $postedAt): int
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');

        return (int) $postedAt->setTimezone($timezone)->startOfDay()->diffInDays(CarbonImmutable::now($timezone)->startOfDay());
    }

    /**
     * @param  array<string, float>  $items
     * @return array{points: float, max: float, items: array<string, float>}
     */
    private function line(float $max, array $items): array
    {
        return ['points' => min($max, round(array_sum($items), 1)), 'max' => $max, 'items' => $items];
    }
}
