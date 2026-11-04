<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\JobBoards\JobAdData;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Models\ScrapeRun;
use App\Models\Signal;
use App\Services\Classification\RecruiterDetector;
use App\Services\Dedupe\CompanyMatcher;
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
     * @return Signal|null null when the ad was skipped (recruiter or unusable employer name)
     */
    public function run(JobAdData $ad, ?ScrapeRun $run = null): ?Signal
    {
        if ($this->recruiters->isRecruiterAd($ad)) {
            return null;
        }

        $normalised = $this->matcher->normalise($ad->employerName);

        if ($normalised === '') {
            return null;
        }

        // Serialise per employer so concurrent workers can't create the same company twice.
        [$signal, $company, $created] = Cache::lock("ingest-company:{$normalised}", 10)->block(5, function () use ($ad, $normalised) {
            return DB::transaction(function () use ($ad, $normalised) {
                $company = Company::query()->where('normalised_name', $normalised)->oldest('id')->first();
                $created = false;

                if ($company === null) {
                    $company = Company::create([
                        'name' => Str::squish($ad->employerName),
                        'normalised_name' => $normalised,
                        'country' => 'GB',
                        'location' => $ad->location,
                        'status' => LeadStatus::Discovered,
                        'pipeline_step' => PipelineStep::RegistrySearch,
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
            $this->advance->run($company);
        }

        return $signal;
    }
}
