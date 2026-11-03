<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\JobBoards\JobAdData;
use App\Models\ScrapeRun;
use App\Models\Signal;

/**
 * Local, sync: store a job ad as a Signal (idempotent on source + external_id).
 *
 * P1.3 extends this with the recruiter filter, company matching/creation, the suppression
 * check and the hand-over to AdvanceLeadPipelineAction.
 */
final class IngestJobAdAction
{
    public function run(JobAdData $ad, ?ScrapeRun $run = null): Signal
    {
        $signal = Signal::query()->updateOrCreate(
            ['source' => $ad->source, 'external_id' => $ad->externalId],
            [
                'title' => $ad->title,
                'employer_name' => $ad->employerName,
                'location' => $ad->location,
                'description' => $ad->description,
                'url' => $ad->url,
                'posted_at' => $ad->postedAt,
                'raw' => $ad->raw,
            ],
        );

        if ($signal->wasRecentlyCreated && $run !== null) {
            $run->increment('signals_found');
        }

        return $signal;
    }
}
