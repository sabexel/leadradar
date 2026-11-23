<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\JobBoards\JobAdData;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\Classification\RecruiterDetector;
use App\Services\Dedupe\CompanyMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local, sync (docs/05 §4): job ad → recruiter filter → Signal upsert → match/create Company
 * → hand new companies to the pipeline.
 *
 * Idempotent: the same ad twice gives one Signal and one Company. Companies are matched on the exact
 * normalised employer name; fuzzy matching is reserved for registry lookups, where a human-checkable
 * candidate list exists (D35).
 */
final class IngestJobAdAction
{
    public function __construct(
        private readonly RecruiterDetector $recruiters,
        private readonly CompanyMatcher $matcher,
        private readonly AdvanceLeadPipelineAction $advance,
    ) {}

    /**
     * New companies today from agency job ads (D117) — capped by `source.agency_jobs.per_day`.
     *
     * @phpstan-impure
     */
    public function agencyJobsRemainingToday(): int
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');
        $created = Company::query()
            ->where('lead_product', LeadProduct::Agency)
            ->where('created_at', '>=', CarbonImmutable::now($timezone)->startOfDay()->utc())
            ->whereHas('signals', fn ($q) => $q->whereIn('source', [SignalSource::Adzuna, SignalSource::Reed]))
            ->count();

        return max(0, (int) Setting::get('source.agency_jobs.per_day') - $created);
    }

    /**
     * @return Signal|null null when the ad was skipped (recruiter or unusable employer name)
     */
    public function run(JobAdData $ad, ?ScrapeRun $run = null, LeadProduct $product = LeadProduct::Laravel, string $country = 'GB'): ?Signal
    {
        $country = strtoupper($country);

        if ($this->recruiters->isRecruiterAd($ad)) {
            return null;
        }

        $normalised = $this->matcher->normalise($ad->employerName);

        if ($normalised === '') {
            return null;
        }

        // Serialise per employer so concurrent workers can't create the same company twice.
        [$signal, $company, $created] = Cache::lock("ingest-company:{$normalised}", 10)->block(5, function () use ($ad, $normalised, $product, $country) {
            return DB::transaction(function () use ($ad, $normalised, $product, $country) {
                $company = Company::query()->where('normalised_name', $normalised)->oldest('id')->first();
                $created = false;

                if ($company === null) {
                    $company = Company::create([
                        'name' => Str::squish($ad->employerName),
                        'normalised_name' => $normalised,
                        'country' => $country,
                        'location' => $ad->location,
                        'lead_product' => $product,
                        'email_consent_required' => in_array($country, (array) config('leadradar.email_consent_countries', []), true),
                        'status' => LeadStatus::Discovered,
                        // Only the UK has a free registry; abroad an agency-ad lead goes to the website search (D117).
                        'pipeline_step' => $country === 'GB' || $product === LeadProduct::Laravel ? PipelineStep::RegistrySearch : PipelineStep::FindWebsite,
                    ]);
                    $created = true;
                }

                $signal = Signal::query()->updateOrCreate(
                    ['source' => $ad->source, 'external_id' => $ad->externalId],
                    [
                        'company_id' => $company->id,
                        'title' => $ad->title,
                        'employer_name' => $ad->employerName,
                        'location' => $ad->location,
                        'description' => $ad->description,
                        'url' => $ad->url,
                        'posted_at' => $ad->postedAt,
                        'raw' => $ad->raw,
                    ],
                );

                return [$signal, $company, $created];
            });
        });

        if ($run !== null) {
            if ($signal->wasRecentlyCreated) {
                $run->increment('signals_found');
            }

            if ($created) {
                $run->increment('companies_created');
            }
        }

        // Existing companies are already in (or past) the pipeline; a new signal just adds evidence.
        if ($created) {
            $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Ok, "Discovered from {$ad->source->value} ad \"{$ad->title}\"", [
                'signal_id' => $signal->id,
            ]);

            $this->advance->run($company);
        }

        return $signal;
    }
}
