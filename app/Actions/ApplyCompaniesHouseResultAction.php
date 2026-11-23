<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CompaniesHouse\CompanyProfile;
use App\DTOs\CompaniesHouse\CompanySearchResult;
use App\DTOs\CompaniesHouse\Officer;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PersonRole;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\Registry;
use App\Enums\RegistryMatchOutcome;
use App\Enums\RejectReason;
use App\Models\Company;
use App\Models\Person;
use App\Services\CompaniesHouse\RegistryMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Local, sync: stores the result of each registry call and applies the registry gates (docs/06).
 * Companies House, KVK and OpenCorporates results share the same DTOs (D110).
 */
final class ApplyCompaniesHouseResultAction
{
    public function __construct(private readonly RegistryMatcher $matcher) {}

    /**
     * @param  Collection<int, CompanySearchResult>  $results
     * @return Company|null the company to advance, or null when it was merged into an existing one
     */
    public function applySearch(Company $company, Collection $results, Registry $registry = Registry::CompaniesHouse): ?Company
    {
        $match = $this->matcher->select($company->name, $company->location, $results);
        $context = ['candidates' => $match->candidates];
        $label = $registry->label();

        // Agency leads (D113) come with their website: a trading name the registry can't place is no reason to drop
        // them — they continue without registry data (and so without registry founders).
        if ($company->lead_product === LeadProduct::Agency && in_array($match->outcome, [RegistryMatchOutcome::NotFound, RegistryMatchOutcome::Ambiguous], true)) {
            $company->update(['pipeline_step' => PipelineStep::Classify]);
            $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Skipped, "No clear {$label} match — continuing as an agency lead without registry data", $context);

            return $company;
        }

        if ($match->outcome === RegistryMatchOutcome::NotFound) {
            $company->reject(RejectReason::NotOnRegistry, "No {$label} match", $context);

            return $company;
        }

        if ($match->outcome === RegistryMatchOutcome::Ambiguous) {
            $company->reject(RejectReason::NotOnRegistry, "Ambiguous {$label} match — review candidates", $context);

            return $company;
        }

        $result = $match->result;

        if ($result === null) {
            $company->reject(RejectReason::NotOnRegistry, "No {$label} match", $context);

            return $company;
        }

        if ($match->outcome === RegistryMatchOutcome::Inactive) {
            $company->update($this->searchAttributes($result, $registry));
            $company->reject(RejectReason::NotActive, "Registry status: {$result->status}", $context);

            return $company;
        }

        // Two employer names (e.g. trading vs legal name) that resolve to one registered company → one lead.
        $existing = Company::query()
            ->where('registry', $registry->value)
            ->where('registration_number', $result->number)
            ->whereKeyNot($company->id)
            ->first();

        if ($existing !== null) {
            DB::transaction(function () use ($company, $existing, $result) {
                $company->signals()->update(['company_id' => $existing->id]);
                $existing->recordEvent($existing->pipeline_step, PipelineOutcome::Ok, "Merged duplicate \"{$company->name}\" (same company number {$result->number})");
                $company->delete();
            });

            return null;
        }

        $company->update([
            ...$this->searchAttributes($result, $registry),
            'status' => LeadStatus::Enriching,
            'pipeline_step' => PipelineStep::RegistryProfile,
        ]);

        $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Ok, "Matched {$result->title} ({$result->number})", [
            'number' => $result->number,
            'similarity' => $match->similarity,
            'candidates' => count($match->candidates),
        ]);

        return $company;
    }

    /**
     * KVK has no officers API, so a KVK company goes straight to classification.
     */
    public function applyProfile(Company $company, ?CompanyProfile $profile, Registry $registry = Registry::CompaniesHouse): Company
    {
        if ($profile === null) {
            $company->reject(RejectReason::NotOnRegistry, "Company number not found on {$registry->label()}");

            return $company;
        }

        $company->update([
            'legal_name' => $profile->name !== '' ? $profile->name : $company->legal_name,
            'company_status' => $profile->status,
            'company_kind' => $profile->type,
            'sic_codes' => $profile->sicCodes,
            'registered_address' => $profile->registeredAddress,
            'incorporated_on' => $profile->dateOfCreation,
            'accounts_category' => $profile->lastAccountsType,
            'registry_no_mailing' => $profile->noMailing,
            'location' => $company->location ?? $profile->locality(),
        ]);

        if ($profile->status !== 'active' || $profile->hasBeenLiquidated) {
            $company->reject(RejectReason::NotActive, 'Registry status: '.($profile->status ?? 'unknown').($profile->hasBeenLiquidated ? ' (liquidated)' : ''));

            return $company;
        }

        if (! in_array($profile->type, $registry->allowedForms(), true)) {
            $company->reject(RejectReason::WrongLegalForm, 'Legal form: '.($profile->type ?? 'unknown'));

            return $company;
        }

        if ($profile->lastAccountsType === 'dormant') {
            $company->reject(RejectReason::Dormant, 'Last accounts filed as dormant');

            return $company;
        }

        if ($profile->noMailing) {
            $company->reject(RejectReason::NoMailing, "Registered with {$registry->label()} as not wanting unsolicited mail");

            return $company;
        }

        $next = $registry === Registry::Kvk ? PipelineStep::Classify : PipelineStep::RegistryOfficers;

        $company->update(['pipeline_step' => $next]);
        $company->recordEvent(PipelineStep::RegistryProfile, PipelineOutcome::Ok, "Active {$profile->type}", [
            'sic_codes' => $profile->sicCodes,
            'accounts' => $profile->lastAccountsType,
        ]);

        return $company;
    }

    /**
     * Stores natural-person directors / LLP members (resigned ones too, without a position).
     * Founder 1/2 = the earliest-appointed active ones.
     *
     * @param  Collection<int, Officer>|null  $officers
     */
    public function applyOfficers(Company $company, ?Collection $officers, Registry $registry = Registry::CompaniesHouse): Company
    {
        $founders = ($officers ?? collect())
            ->filter(fn (Officer $o): bool => $o->isFounderRole() && $o->firstName !== '' && $o->lastName !== '')
            ->values();

        $active = $founders
            ->filter(fn (Officer $o): bool => $o->isActive())
            ->sortBy(fn (Officer $o): string => $o->appointedOn?->toDateString() ?? '9999-12-31')
            ->values();

        $source = $registry->dataSource();

        DB::transaction(function () use ($company, $founders, $active, $source, $registry) {
            // Positions are recomputed from scratch so a re-run gives the same result.
            $company->people()->where('source', $source)->update(['position' => null]);

            foreach ($founders as $officer) {
                $position = $active->search($officer, strict: true);

                Person::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'source' => $source,
                        'first_name' => $officer->firstName,
                        'last_name' => $officer->lastName,
                        'appointed_on' => $officer->appointedOn,
                    ],
                    [
                        'role' => match ($officer->role) {
                            'director' => PersonRole::Director,
                            'founder' => PersonRole::Founder,
                            default => PersonRole::LlpMember,
                        },
                        'resigned_on' => $officer->resignedOn,
                        'country_of_residence' => $officer->countryOfResidence,
                        'position' => $position === false ? null : $position + 1,
                        'source_url' => $registry->officersUrl((string) $company->registration_number),
                    ],
                );
            }
        });

        $company->update(['pipeline_step' => PipelineStep::Classify]);
        $company->recordEvent(
            PipelineStep::RegistryOfficers,
            $officers === null ? PipelineOutcome::Skipped : PipelineOutcome::Ok,
            $officers === null ? 'Officers list not available' : "{$active->count()} active director(s)",
            ['active' => $active->count(), 'resigned' => $founders->count() - $active->count()],
        );

        return $company;
    }

    /**
     * @return array<string, mixed>
     */
    private function searchAttributes(CompanySearchResult $result, Registry $registry): array
    {
        return [
            'registration_number' => $result->number,
            'legal_name' => $result->title,
            'registry' => $registry->value,
            'company_status' => $result->status,
            'company_kind' => $result->type,
        ];
    }
}
