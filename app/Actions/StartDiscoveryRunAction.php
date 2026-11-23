<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadProduct;
use App\Enums\RunTrigger;
use App\Enums\ScrapeRunStatus;
use App\Jobs\Sources\FetchAdzunaJobsJob;
use App\Jobs\Sources\FetchAgencySearchJob;
use App\Jobs\Sources\FetchBusinessSearchJob;
use App\Jobs\Sources\FetchCompaniesHouseSicJob;
use App\Jobs\Sources\FetchGooglePlacesJob;
use App\Jobs\Sources\FetchLaravelPartnersJob;
use App\Jobs\Sources\FetchReedJobsJob;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Services\Search\AgencySearchPlanner;
use App\Services\Search\BusinessSearchPlanner;
use App\Services\Support\DailyTarget;
use Carbon\CarbonImmutable;

/**
 * Local, sync: start a discovery run (docs/05 "Discovery run"). Used by `leadradar:discover` and, later,
 * the dashboard's "Run now" button.
 */
final class StartDiscoveryRunAction
{
    public function __construct(
        private readonly DailyTarget $target,
        private readonly IngestRegistryCompanyAction $registry,
        private readonly IngestWebsitePlaceAction $places,
        private readonly IngestAgencyAction $agencies,
        private readonly AgencySearchPlanner $agencySearches,
        private readonly BusinessSearchPlanner $businessSearches,
    ) {}

    /**
     * @return array{run: ScrapeRun|null, dispatched: list<string>, skipped: list<string>, message: string}
     */
    public function run(RunTrigger $trigger = RunTrigger::Schedule): array
    {
        $running = ScrapeRun::query()->where('status', ScrapeRunStatus::Running)->latest('id')->first();

        if ($running !== null) {
            return ['run' => $running, 'dispatched' => [], 'skipped' => [], 'message' => "Run #{$running->id} is still running — not starting another."];
        }

        $run = ScrapeRun::create(['started_at' => now(), 'trigger' => $trigger, 'status' => ScrapeRunStatus::Running]);

        if ($this->target->reached()) {
            $run->update(['status' => ScrapeRunStatus::StoppedTargetReached, 'finished_at' => now(), 'qualified_count' => 0]);

            return ['run' => $run, 'dispatched' => [], 'skipped' => [], 'message' => "Today's target of {$this->target->target()} qualified leads is already reached."];
        }

        $keywords = array_values(array_filter((array) Setting::get('search.keywords'), fn (mixed $k): bool => is_string($k) && trim($k) !== ''));
        $countries = array_map('strtoupper', (array) Setting::get('search.countries'));
        $dispatched = [];
        $skipped = [];

        // Reed and Adzuna (gb) are both UK job boards.
        if (! in_array('GB', $countries, true)) {
            $skipped[] = 'reed + adzuna: GB not in search.countries';
        } else {
            $reed = filled(config('services.reed.key'));
            $adzuna = filled(config('services.adzuna.app_id')) && filled(config('services.adzuna.app_key'));

            foreach ($keywords as $keyword) {
                if ($reed) {
                    FetchReedJobsJob::dispatch($keyword, 1, $run->id);
                    $dispatched[] = "reed:{$keyword}";
                }

                if ($adzuna) {
                    FetchAdzunaJobsJob::dispatch($keyword, 1, $run->id);
                    $dispatched[] = "adzuna:{$keyword}";
                }
            }

            if (! $reed) {
                $skipped[] = 'reed: REED_API_KEY not set';
            }

            if (! $adzuna) {
                $skipped[] = 'adzuna: ADZUNA_APP_ID / ADZUNA_APP_KEY not set';
            }
        }

        // Companies House SIC + location (D106): GB registry, own daily cap; 0 turns it off.
        if (in_array('GB', $countries, true) && (int) Setting::get('source.ch_sic.per_day') > 0) {
            if (! filled(config('services.companies_house.key'))) {
                $skipped[] = 'companies house sic: COMPANIES_HOUSE_API_KEY not set';
            } else {
                $codes = array_values(array_filter((array) Setting::get('source.ch_sic.sic_codes'), fn (mixed $c): bool => is_string($c) && preg_match('/^\d{5}$/', $c) === 1));
                $locations = array_values(array_filter(array_map(fn (mixed $l): string => is_string($l) ? trim($l) : '', (array) Setting::get('source.ch_sic.locations'))));
                $pairs = [];

                foreach ($locations as $location) {
                    foreach ($codes as $code) {
                        $pairs[] = [$code, $location];
                    }
                }

                $remaining = $this->registry->remainingToday();

                if ($pairs === []) {
                    $skipped[] = 'companies house sic: no SIC codes or locations set';
                } elseif ($remaining === 0) {
                    $skipped[] = "companies house sic: today's cap (source.ch_sic.per_day) already used";
                } else {
                    // Each pair gets an equal share of what's left today; the order turns daily so the pairs that
                    // the cap runs out before are not always the same ones.
                    $share = (int) ceil($remaining / count($pairs));
                    $turn = CarbonImmutable::now((string) config('leadradar.timezone', 'Europe/London'))->dayOfYear % count($pairs);

                    foreach ([...array_slice($pairs, $turn), ...array_slice($pairs, 0, $turn)] as [$code, $location]) {
                        FetchCompaniesHouseSicJob::dispatch($code, $location, $share, 1, $run->id);
                        $dispatched[] = "companies_house_sic:{$code}:{$location}";
                    }
                }
            }
        }

        // Laravel Partners directory (P4.1, D108): UK partners only, one page request.
        if (in_array('GB', $countries, true) && (bool) Setting::get('source.laravel_partners.enabled')) {
            FetchLaravelPartnersJob::dispatch($run->id);
            $dispatched[] = 'laravel_partners';
        }

        // Website leads — Google Places, no website, min reviews (D111). Separate from Laravel product.
        if (in_array('GB', $countries, true) && (int) Setting::get('source.places.per_day') > 0) {
            if (! filled(config('services.google_places.key'))) {
                $skipped[] = 'google places: GOOGLE_PLACES_API_KEY not set';
            } else {
                $allowedTypes = array_keys(config('leadradar.place_types', []));
                $types = array_values(array_filter(
                    (array) Setting::get('source.places.types'),
                    fn (mixed $t): bool => is_string($t) && in_array($t, $allowedTypes, true),
                ));
                $cities = array_values(array_filter(array_map(
                    fn (mixed $c): string => is_string($c) ? trim($c) : '',
                    (array) Setting::get('source.places.cities'),
                )));
                $pairs = [];

                foreach ($cities as $city) {
                    foreach ($types as $type) {
                        $pairs[] = [$city, $type];
                    }
                }

                $remaining = $this->places->remainingToday();

                if ($pairs === []) {
                    $skipped[] = 'google places: no cities or business types set';
                } elseif ($remaining === 0) {
                    $skipped[] = "google places: today's cap (source.places.per_day) already used";
                } else {
                    $share = (int) ceil($remaining / count($pairs));
                    $turn = CarbonImmutable::now((string) config('leadradar.timezone', 'Europe/London'))->dayOfYear % count($pairs);

                    foreach ([...array_slice($pairs, $turn), ...array_slice($pairs, 0, $turn)] as [$city, $type]) {
                        FetchGooglePlacesJob::dispatch($city, $type, $share, 1, null, $run->id);
                        $dispatched[] = "google_places:{$type}:{$city}";
                    }
                }
            }
        }

        // Job ads for WordPress / Shopify / WooCommerce work in every Adzuna market (D117).
        if ((int) Setting::get('source.agency_jobs.per_day') > 0) {
            if (! filled(config('services.adzuna.app_id')) || ! filled(config('services.adzuna.app_key'))) {
                $skipped[] = 'agency job ads: ADZUNA_APP_ID / ADZUNA_APP_KEY not set';
            } else {
                $agencyKeywords = array_values(array_filter((array) Setting::get('search.agency_keywords'), fn (mixed $k): bool => is_string($k) && trim($k) !== ''));
                $jobCountries = array_values(array_filter((array) Setting::get('search.agency_job_countries'), fn (mixed $c): bool => is_string($c) && preg_match('/^[a-z]{2}$/', $c) === 1));

                foreach ($jobCountries as $jobCountry) {
                    foreach ($agencyKeywords as $keyword) {
                        FetchAdzunaJobsJob::dispatch(trim($keyword), 1, $run->id, $jobCountry, LeadProduct::Agency->value);
                        $dispatched[] = "adzuna:{$jobCountry}:{$keyword}";
                    }
                }
            }
        }

        // Agency leads of any stack, worldwide, via web search (D113).
        if ((int) Setting::get('source.agency.per_day') > 0) {
            if (! filled(config('services.brave.key'))) {
                $skipped[] = 'agency search: BRAVE_SEARCH_API_KEY not set';
            } elseif ($this->agencies->remainingToday() === 0) {
                $skipped[] = "agency search: today's cap (source.agency.per_day) already used";
            } else {
                $batch = $this->agencySearches->nextBatch();

                foreach ($batch as $search) {
                    FetchAgencySearchJob::dispatch($search['phrase'], $search['city'], $search['country'], $search['offset'], $run->id);
                    $dispatched[] = "agency_search:{$search['country']}:{$search['city']}:{$search['phrase']}";
                }

                if ($batch === []) {
                    $skipped[] = 'agency search: no markets enabled';
                }
            }
        }

        // Businesses that may need agency services — UAE first (D127).
        if ((int) Setting::get('source.business.per_day') > 0 && filled(config('services.brave.key'))) {
            foreach ($this->businessSearches->nextBatch() as $search) {
                FetchBusinessSearchJob::dispatch($search['phrase'], $search['city'], $search['country'], $search['offset'], $run->id);
                $dispatched[] = "business_search:{$search['country']}:{$search['city']}:{$search['phrase']}";
            }
        }

        if ($dispatched === []) {
            $run->update(['status' => ScrapeRunStatus::Failed, 'finished_at' => now()]);

            return ['run' => $run, 'dispatched' => [], 'skipped' => $skipped, 'message' => 'Nothing to search: check keys, search.keywords and search.countries.'];
        }

        return ['run' => $run, 'dispatched' => $dispatched, 'skipped' => $skipped, 'message' => "Run #{$run->id} started."];
    }
}
