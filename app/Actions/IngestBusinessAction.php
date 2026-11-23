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
 * Local, sync: one business found by "{category} {city}" search (D127) → a Business lead (it may need agency
 * services) with its website known; it goes straight to the crawl, where its site is audited.
 */
final class IngestBusinessAction
{
    public function __construct(
        private readonly CompanyMatcher $matcher,
        private readonly SuppressionList $suppressions,
        private readonly AdvanceLeadPipelineAction $advance,
    ) {}

    /**
     * @phpstan-impure
     */
    public function remainingToday(): int
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');
        $created = Signal::query()
            ->where('source', SignalSource::BusinessSearch)
            ->where('created_at', '>=', CarbonImmutable::now($timezone)->startOfDay()->utc())
            ->count();

        return max(0, (int) Setting::get('source.business.per_day') - $created);
    }

    /**
     * @param  array{name: string, domain: string, url: string, title: string}  $business
     */
    public function run(array $business, string $country, string $city, string $category, ?ScrapeRun $run = null): bool
    {
        $country = strtoupper($country);
        $normalised = $this->matcher->normalise($business['name']);

        if ($normalised === '' || $this->suppressions->isSuppressed(new Company(['domain' => $business['domain']]))) {
            return false;
        }

        $company = Cache::lock("ingest-domain:{$business['domain']}", 10)->block(5, fn () => DB::transaction(function () use ($business, $country, $city, $category, $normalised): ?Company {
            if (Company::query()->where('domain', $business['domain'])->exists()) {
                return null;
            }

            $company = Company::create([
                'name' => $business['name'],
                'normalised_name' => $normalised,
                'website' => $business['url'],
                'domain' => $business['domain'],
                'country' => $country,
                'location' => $city,
                'lead_product' => LeadProduct::Business,
                'company_type' => CompanyType::DirectClient,
                'email_consent_required' => in_array($country, (array) config('leadradar.email_consent_countries', []), true),
                'status' => LeadStatus::Discovered,
                'pipeline_step' => PipelineStep::FindWebsite,
            ]);

            Signal::query()->updateOrCreate(['source' => SignalSource::BusinessSearch, 'external_id' => substr($business['domain'], 0, 190)], [
                'company_id' => $company->id,
                'title' => mb_substr("{$category} ({$city})", 0, 250),
                'employer_name' => mb_substr($business['name'], 0, 250),
                'location' => $city,
                'url' => $business['url'],
                'posted_at' => now(),
                'raw' => ['category' => $category, 'country' => $country, 'city' => $city, 'title' => $business['title']],
            ]);

            return $company;
        }));

        if ($company === null) {
            return false;
        }

        $run?->increment('signals_found');
        $run?->increment('companies_created');
        $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Ok, "Found by business search \"{$category} {$city}\" ({$country})", ['domain' => $business['domain']]);
        $this->advance->run($company);

        return true;
    }
}
