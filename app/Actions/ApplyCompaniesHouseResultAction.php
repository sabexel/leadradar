<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CompaniesHouse\CompanyProfile;
use App\DTOs\CompaniesHouse\CompanySearchResult;
use App\DTOs\CompaniesHouse\Officer;
use App\Enums\DataSource;
use App\Enums\LeadStatus;
use App\Enums\PersonRole;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RegistryMatchOutcome;
use App\Enums\RejectReason;
use App\Models\Company;
use App\Models\Person;
use App\Services\CompaniesHouse\RegistryMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Local, sync: stores the result of each Companies House call and applies the registry gates (docs/06).
 */
final class ApplyCompaniesHouseResultAction
{
    /**
     * UK legal forms that are corporate subscribers under PECR (docs/09).
     */
    public const ALLOWED_FORMS = ['ltd', 'llp', 'plc'];

    public function __construct(private readonly RegistryMatcher $matcher) {}

    /**
     * @param  Collection<int, CompanySearchResult>  $results
     * @return Company|null the company to advance, or null when it was merged into an existing one
     */
    public function applySearch(Company $company, Collection $results): ?Company
    {
        $match = $this->matcher->select($company->name, $company->location, $results);
        $context = ['candidates' => $match->candidates];

        if ($match->outcome === RegistryMatchOutcome::NotFound) {
            $company->reject(RejectReason::NotOnRegistry, 'No Companies House match', $context);

            return $company;
        }

        if ($match->outcome === RegistryMatchOutcome::Ambiguous) {
            $company->reject(RejectReason::NotOnRegistry, 'Ambiguous Companies House match — review candidates', $context);

            return $company;
        }

        $result = $match->result;

        if ($result === null) {
            $company->reject(RejectReason::NotOnRegistry, 'No Companies House match', $context);

            return $company;
        }

        if ($match->outcome === RegistryMatchOutcome::Inactive) {
            $company->update($this->searchAttributes($result));
            $company->reject(RejectReason::NotActive, "Registry status: {$result->status}", $context);

            return $company;
        }

        // Two employer names (e.g. trading vs legal name) that resolve to one registered company → one lead.
        $existing = Company::query()
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
            ...$this->searchAttributes($result),
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

    public function applyProfile(Company $company, ?CompanyProfile $profile): Company
    {
        if ($profile === null) {
            $company->reject(RejectReason::NotOnRegistry, 'Company number not found on Companies House');

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
            'location' => $company->location ?? $profile->locality(),
        ]);

        if ($profile->status !== 'active' || $profile->hasBeenLiquidated) {
            $company->reject(RejectReason::NotActive, 'Registry status: '.($profile->status ?? 'unknown').($profile->hasBeenLiquidated ? ' (liquidated)' : ''));

            return $company;
        }

        if (! in_array($profile->type, self::ALLOWED_FORMS, true)) {
            $company->reject(RejectReason::WrongLegalForm, 'Legal form: '.($profile->type ?? 'unknown'));

            return $company;
        }

        if ($profile->lastAccountsType === 'dormant') {
            $company->reject(RejectReason::Dormant, 'Last accounts filed as dormant');

            return $company;
        }

        $company->update(['pipeline_step' => PipelineStep::RegistryOfficers]);
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
    public function applyOfficers(Company $company, ?Collection $officers): Company
    {
        $founders = ($officers ?? collect())
            ->filter(fn (Officer $o): bool => $o->isFounderRole() && $o->firstName !== '' && $o->lastName !== '')
            ->values();

        $active = $founders
            ->filter(fn (Officer $o): bool => $o->isActive())
            ->sortBy(fn (Officer $o): string => $o->appointedOn?->toDateString() ?? '9999-12-31')
            ->values();

        DB::transaction(function () use ($company, $founders, $active) {
            // Positions are recomputed from scratch so a re-run gives the same result.
            $company->people()->where('source', DataSource::CompaniesHouse)->update(['position' => null]);

            foreach ($founders as $officer) {
                $position = $active->search($officer, strict: true);

                Person::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'source' => DataSource::CompaniesHouse,
                        'first_name' => $officer->firstName,
                        'last_name' => $officer->lastName,
                        'appointed_on' => $officer->appointedOn,
                    ],
                    [
                        'role' => $officer->role === 'director' ? PersonRole::Director : PersonRole::LlpMember,
                        'resigned_on' => $officer->resignedOn,
                        'country_of_residence' => $officer->countryOfResidence,
                        'position' => $position === false ? null : $position + 1,
                        'source_url' => $this->officersPageUrl($company),
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
    private function searchAttributes(CompanySearchResult $result): array
    {
        return [
            'registration_number' => $result->number,
            'legal_name' => $result->title,
            'registry' => 'companies_house',
            'company_status' => $result->status,
            'company_kind' => $result->type,
        ];
    }

    private function officersPageUrl(Company $company): string
    {
        return "https://find-and-update.company-information.service.gov.uk/company/{$company->registration_number}/officers";
    }
}
