<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CompaniesHouse\RegistryCompany;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\Registry;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Support\SuppressionList;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local, sync: one company from the Companies House SIC + location search → a Company already matched to the
 * registry, sent into the pipeline at `registry_profile` (the search step is skipped — the number is known). D106.
 */
final class IngestRegistryCompanyAction
{
    public function __construct(
        private readonly CompanyMatcher $matcher,
        private readonly SuppressionList $suppressions,
        private readonly AdvanceLeadPipelineAction $advance,
    ) {}

    /**
     * New companies from this source today (London business day) — the daily cap counts these.
     *
     * @phpstan-impure
     */
    public function createdToday(): int
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');

        return Signal::query()
            ->where('source', SignalSource::CompaniesHouseSic)
            ->where('created_at', '>=', CarbonImmutable::now($timezone)->startOfDay()->utc())
            ->count();
    }

    /**
     * @phpstan-impure reads today's count from the database each time
     */
    public function remainingToday(): int
    {
        return max(0, (int) Setting::get('source.ch_sic.per_day') - $this->createdToday());
    }

    /**
     * @return bool true when a new company was created (false: skipped — known, suppressed, or not a fit)
     */
    public function run(RegistryCompany $result, string $sicCode, string $location, ?ScrapeRun $run = null): bool
    {
        // Only forms the gates accept, and only live companies (docs/06) — nothing is spent on the rest.
        if ($result->status !== 'active' || ! in_array($result->type, Registry::CompaniesHouse->allowedForms(), true)) {
            return false;
        }

        $name = $this->displayName($result->name);
        $normalised = $this->matcher->normalise($name);

        if ($normalised === '' || $this->suppressions->isSuppressed(new Company(['registration_number' => $result->number]))) {
            return false;
        }

        $company = Cache::lock("ingest-company:{$normalised}", 10)->block(5, fn () => DB::transaction(function () use ($result, $name, $normalised, $sicCode, $location): ?Company {
            $known = Company::query()
                ->where('registration_number', $result->number)
                ->orWhere('normalised_name', $normalised)
                ->exists();

            if ($known) {
                return null;
            }

            $company = Company::create([
                'name' => $name,
                'legal_name' => $result->name,
                'normalised_name' => $normalised,
                'country' => 'GB',
                'location' => $result->locality === null ? null : Str::title(mb_strtolower($result->locality)),
                'registered_address' => $result->registeredAddress === [] ? null : $result->registeredAddress,
                'registration_number' => $result->number,
                'registry' => 'companies_house',
                'company_status' => $result->status,
                'company_kind' => $result->type,
                'incorporated_on' => $result->dateOfCreation,
                'sic_codes' => $result->sicCodes,
                'status' => LeadStatus::Discovered,
                'pipeline_step' => PipelineStep::RegistryProfile,
            ]);

            Signal::create([
                'company_id' => $company->id,
                'source' => SignalSource::CompaniesHouseSic,
                'external_id' => $result->number,
                'title' => "SIC {$sicCode} in {$location}",
                'employer_name' => $result->name,
                'location' => $company->location,
                'url' => 'https://find-and-update.company-information.service.gov.uk/company/'.rawurlencode($result->number),
                'posted_at' => now(),
                'raw' => ['number' => $result->number, 'sic_codes' => $result->sicCodes, 'search' => ['sic' => $sicCode, 'location' => $location]],
            ]);

            return $company;
        }));

        if ($company === null) {
            return false;
        }

        $run?->increment('signals_found');
        $run?->increment('companies_created');

        $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Ok, "Found by Companies House search: SIC {$sicCode} in {$location}", [
            'number' => $result->number,
        ]);

        $this->advance->run($company);

        return true;
    }

    /**
     * "EXAMPLE DIGITAL LTD" → "Example Digital Ltd"; mixed-case names are kept as registered.
     */
    private function displayName(string $name): string
    {
        $name = Str::squish($name);

        return $name === mb_strtoupper($name) ? ucwords(mb_strtolower($name), " -'&(") : $name;
    }
}
