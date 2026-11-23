<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\LeadScore;
use App\Enums\CompanyType;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadProduct;
use App\Enums\Registry;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Signal;
use App\Services\Crawler\TechStackDetector;
use App\Services\Support\SuppressionList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Local: hard gates + transparent 0–10 score, exactly per docs/06. Always computes the score, also for leads
 * that fail a gate (for analysis).
 */
final class LeadScoringService
{
    /**
     * Companies House last-accounts types filed by small companies (D72).
     */
    public const SMALL_ACCOUNTS = ['micro-entity', 'small', 'total-exemption-small', 'total-exemption-full', 'unaudited-abridged', 'audited-abridged'];

    private const JOB_BOARDS = [SignalSource::Reed, SignalSource::Adzuna];

    public function __construct(private readonly SuppressionList $suppressions) {}

    public function score(Company $company): LeadScore
    {
        $company->loadMissing(['signals', 'emailAddresses', 'people', 'contactEmail', 'careersEmail']);

        if ($company->lead_product === LeadProduct::Business) {
            $breakdown = $this->businessBreakdown($company);
            $total = min(10.0, round(array_sum(array_column($breakdown, 'points')), 1));

            // Same gates as agencies (website, real address, not a recruiter, not suppressed).
            return new LeadScore($total, $breakdown, $this->failedAgencyGate($company));
        }

        if ($company->lead_product === LeadProduct::Agency) {
            $breakdown = $this->agencyBreakdown($company);
            $total = min(10.0, round(array_sum(array_column($breakdown, 'points')), 1));

            return new LeadScore($total, $breakdown, $this->failedAgencyGate($company));
        }

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
        $registry = Registry::forCountry($company->country);

        return match (true) {
            $registry === null || $company->registry !== $registry->value || $company->registration_number === null => RejectReason::NotOnRegistry,
            $company->company_status !== 'active' => RejectReason::NotActive,
            ! in_array($company->company_kind, $registry->allowedForms(), true) => RejectReason::WrongLegalForm,
            $company->accounts_category === 'dormant' => RejectReason::Dormant,
            $company->registry_no_mailing => RejectReason::NoMailing,
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
     * Gates for agency leads of any stack (D116): no Laravel evidence and no registry needed. Registry facts are
     * still applied when the lead was matched (UK); the email must be real and verified.
     */
    public function failedAgencyGate(Company $company): ?RejectReason
    {
        $company->loadMissing(['emailAddresses', 'people']);
        $registered = $company->registration_number !== null && $company->registry !== null;

        return match (true) {
            $registered && $company->company_status !== null && $company->company_status !== 'active' => RejectReason::NotActive,
            $registered && $company->accounts_category === 'dormant' => RejectReason::Dormant,
            $company->registry_no_mailing => RejectReason::NoMailing,
            $company->company_type === CompanyType::Recruiter => RejectReason::Recruiter,
            $company->company_type === CompanyType::Offshore => RejectReason::Offshore,
            $company->website === null => RejectReason::NoWebsite,
            ! $this->isAgencyContactable($company) => RejectReason::NoValidEmail,
            $this->suppressions->isSuppressed($company) => RejectReason::Suppressed,
            default => null,
        };
    }

    /**
     * The highest score an agency lead could still reach after the crawl (D119): stack and fit are known; reach
     * and inboxes are taken at their best — a verified founder (only with registry founders in a country that
     * allows guessed addresses, else a named person from the site), LinkedIn if founders exist, a verified inbox.
     */
    public function bestPossibleAgencyScore(Company $company): float
    {
        $company->loadMissing(['signals', 'emailAddresses', 'people', 'contactEmail', 'careersEmail']);
        $breakdown = $this->agencyBreakdown($company);

        $hasFounders = $this->founders($company)->isNotEmpty();
        $reach = $hasFounders ? 3.0 : 1.5; // founder 2.0 + LinkedIn 0.5 (+ named person), else a named person only
        $inboxes = 1.5 + ($company->careersEmail !== null ? 0.5 : 0.0);

        return round($breakdown['stack']['points'] + $breakdown['company_fit']['points'] + $reach + $inboxes, 1);
    }

    /**
     * 0–10 for businesses that may need agency services (D127): how much their website needs work, and whether we
     * can reach them.
     *
     * @return array<string, array{points: float, max: float, items: array<string, float>}>
     */
    private function businessBreakdown(Company $company): array
    {
        $stack = $company->tech_stack ?? [];
        $has = fn (string $flag): bool => in_array($flag, $stack, true);
        $personOk = $company->emailAddresses->contains(fn (EmailAddress $e): bool => $e->kind === EmailKind::Person && $e->verification_status === EmailVerificationStatus::Ok);
        $anyOk = $company->emailAddresses->contains(fn (EmailAddress $e): bool => $e->verification_status === EmailVerificationStatus::Ok);

        return [
            'website_needs_work' => $this->line(5.0, [
                'not_mobile_ready' => $has('audit:no_viewport') ? 2.0 : 0.0,
                'no_https' => $has('audit:no_https') ? 1.5 : 0.0,
                'outdated' => $has('audit:old_copyright') ? 1.0 : 0.0,
                'no_description' => $has('audit:no_meta_description') ? 0.5 : 0.0,
                'site_builder' => $has('built:wix') || $has('built:squarespace') ? 0.5 : 0.0,
            ]),
            'reach' => $this->line(3.0, [
                'contact_ok' => $company->contactEmail?->verification_status === EmailVerificationStatus::Ok ? 1.5 : 0.0,
                'named_person_ok' => $personOk ? 1.5 : 0.0,
                'other_address_ok' => $anyOk && $company->contactEmail?->verification_status !== EmailVerificationStatus::Ok && ! $personOk ? 1.0 : 0.0,
            ]),
            'fit' => $this->line(2.0, [
                'local_domain' => $company->domain !== null && str_ends_with($company->domain, '.'.strtolower($company->country)) ? 0.5 : 0.0,
                'established_site' => $has('built:wordpress') || $has('built:shopify') || $has('built:woocommerce') ? 0.5 : 0.0,
                'has_careers' => $company->careersEmail !== null ? 1.0 : 0.0,
            ]),
        ];
    }

    /**
     * Best possible business score after the crawl: the website line is known; reach at its best.
     */
    public function bestPossibleBusinessScore(Company $company): float
    {
        $company->loadMissing(['signals', 'emailAddresses', 'people', 'contactEmail', 'careersEmail']);
        $breakdown = $this->businessBreakdown($company);

        return round($breakdown['website_needs_work']['points'] + 3.0 + $breakdown['fit']['points'], 1);
    }

    /**
     * Any address MillionVerifier confirmed `ok`, or one the agency published itself that is `catch_all`.
     */
    public function isAgencyContactable(Company $company): bool
    {
        return $company->emailAddresses->contains(fn (EmailAddress $e): bool => $e->verification_status === EmailVerificationStatus::Ok
            || ($e->verification_status === EmailVerificationStatus::CatchAll && $e->origin === EmailOrigin::Crawled));
    }

    /**
     * 0–10 for agency leads (D116): what they build with, agency fit, who we can reach, and their inboxes.
     *
     * @return array<string, array{points: float, max: float, items: array<string, float>}>
     */
    private function agencyBreakdown(Company $company): array
    {
        $stack = $company->tech_stack ?? [];
        $offered = count(TechStackDetector::offeredServed($stack));
        $builtServed = array_filter(TechStackDetector::SERVED, fn (string $s): bool => in_array("built:{$s}", $stack, true)) !== [];
        $founders = $this->founders($company);
        $first = $founders->firstWhere('position', 1);
        $firstEmail = $first === null ? null : $this->emailOf($company, $first);
        $founderIds = $founders->pluck('id')->all();
        $personOk = $company->emailAddresses->contains(fn (EmailAddress $e): bool => $e->kind === EmailKind::Person
            && ! in_array($e->person_id, $founderIds, true)
            && $e->verification_status === EmailVerificationStatus::Ok);

        return [
            'stack' => $this->line(3.0, [
                // A job ad (Reed/Adzuna) for a stack we serve: they need hands for exactly that work (D117).
                'hiring_for_served_stack' => $this->hiresForServedStack($company) ? 1.5 : 0.0,
                'offers_served_stack' => match (true) {
                    $offered >= 2 => 2.5,
                    $offered === 1 => 1.5,
                    default => 0.0,
                },
                'site_built_with_served_stack' => $builtServed ? 0.5 : 0.0,
            ]),
            'company_fit' => $this->line(2.0, [
                'type' => match ($company->company_type) {
                    CompanyType::Agency => 1.5,
                    CompanyType::DirectClient => 0.75,
                    default => 0.0,
                },
                'registered_or_established' => ($company->registration_number !== null && $company->company_status === 'active')
                    || ($company->incorporated_on !== null && $company->incorporated_on->lessThanOrEqualTo(now()->subYears(2))) ? 0.5 : 0.0,
            ]),
            'decision_maker_reach' => $this->line(3.0, [
                'founder_email' => match ($firstEmail?->verification_status) {
                    EmailVerificationStatus::Ok => 2.0,
                    EmailVerificationStatus::CatchAll => $firstEmail->origin === EmailOrigin::Crawled ? 1.0 : 0.0,
                    default => 0.0,
                },
                'named_person_email' => $personOk ? 1.5 : 0.0,
                'founder_linkedin' => $first?->linkedin_url !== null ? 0.5 : 0.0,
            ]),
            'company_inboxes' => $this->line(2.0, [
                'contact_ok' => $company->contactEmail?->verification_status === EmailVerificationStatus::Ok ? 1.5 : 0.0,
                'careers_found' => $company->careersEmail !== null ? 0.5 : 0.0,
            ]),
        ];
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
     * ≥ 1 strong signal, a Laravel job ad from this company, or a Laravel Partners listing (D108).
     */
    public function hasLaravelEvidence(Company $company): bool
    {
        return $this->laravelAds($company) !== []
            || $this->isListedPartner($company)
            || collect($company->laravel_evidence ?? [])->contains(fn (array $s): bool => ($s['weight'] ?? null) === 'strong');
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
            'partner' => $this->isListedPartner($company) || $types->contains(fn (array $s): bool => $s['type'] === 'laravel_partner') ? 1.0 : 0.0,
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

    private function hiresForServedStack(Company $company): bool
    {
        return $company->signals->contains(fn (Signal $s): bool => in_array($s->source, self::JOB_BOARDS, true)
            && preg_match('/\b(?:wordpress|woo ?commerce|shopify|laravel|php|magento|webflow|drupal)\b/i', $s->title.' '.($s->description ?? '')) === 1);
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
     * Found on laravel.com/partners (P4.1). Kept as a signal, so a website retry that clears the crawl
     * evidence (D104) does not lose it.
     */
    private function isListedPartner(Company $company): bool
    {
        return $company->signals->contains(fn (Signal $s): bool => $s->source === SignalSource::LaravelPartners);
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
