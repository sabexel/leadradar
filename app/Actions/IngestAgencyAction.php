<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\CompanyType;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
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

/**
 * Local, sync: one agency found by web search (D113) → an Agency lead with its website already known, sent into
 * the pipeline. UK agencies start at the (free) Companies House search for founders; elsewhere the registry step
 * is skipped and the lead goes straight to classification and the crawl.
 */
final class IngestAgencyAction
{
    public function __construct(
        private readonly CompanyMatcher $matcher,
        private readonly SuppressionList $suppressions,
        private readonly AdvanceLeadPipelineAction $advance,
    ) {}

    /**
     * @phpstan-impure
     */
    public function createdToday(): int
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');

        return Signal::query()
            ->where('source', SignalSource::AgencySearch)
            ->where('created_at', '>=', CarbonImmutable::now($timezone)->startOfDay()->utc())
            ->count();
    }

    /**
     * @phpstan-impure
     */
    public function remainingToday(): int
    {
        return max(0, (int) Setting::get('source.agency.per_day') - $this->createdToday());
    }

    /**
     * @param  array{name: string, domain: string, url: string, title: string}  $agency
     * @return bool true when a new lead was created
     */
    public function run(array $agency, string $country, string $city, string $query, ?ScrapeRun $run = null): bool
    {
        $country = strtoupper($country);
        $normalised = $this->matcher->normalise($agency['name']);

        if ($normalised === '' || $this->suppressions->isSuppressed(new Company(['domain' => $agency['domain']]))) {
            return false;
        }

        $company = Cache::lock("ingest-domain:{$agency['domain']}", 10)->block(5, fn () => DB::transaction(function () use ($agency, $country, $city, $query, $normalised): ?Company {
            $known = Company::query()
                ->where('domain', $agency['domain'])
                ->orWhere(fn ($q) => $q->where('normalised_name', $normalised)->where('country', $country))
                ->exists();

            if ($known) {
                return null;
            }

            $company = Company::create([
                'name' => $agency['name'],
                'normalised_name' => $normalised,
                'website' => $agency['url'],
                'domain' => $agency['domain'],
                'country' => $country,
                'location' => $city,
                'lead_product' => LeadProduct::Agency,
                'company_type' => CompanyType::Agency,
                'email_consent_required' => in_array($country, (array) config('leadradar.email_consent_countries', []), true),
                'status' => LeadStatus::Discovered,
                'pipeline_step' => $country === 'GB' ? PipelineStep::RegistrySearch : PipelineStep::Classify,
            ]);

            // Keyed like job ads (source + external id): a signal left by a deleted lead is reused, not duplicated.
            Signal::query()->updateOrCreate(['source' => SignalSource::AgencySearch, 'external_id' => substr($agency['domain'], 0, 190)], [
                'company_id' => $company->id,
                'title' => mb_substr("{$query} ({$city})", 0, 250),
                'employer_name' => mb_substr($agency['name'], 0, 250),
                'location' => $city,
                'url' => $agency['url'],
                'posted_at' => now(),
                'raw' => ['query' => $query, 'country' => $country, 'city' => $city, 'title' => $agency['title']],
            ]);

            return $company;
        }));

        if ($company === null) {
            return false;
        }

        $run?->increment('signals_found');
        $run?->increment('companies_created');

        $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Ok, "Found by agency search \"{$query}\" ({$city}, {$country})", [
            'domain' => $agency['domain'],
        ]);

        $this->advance->run($company);

        return true;
    }
}
