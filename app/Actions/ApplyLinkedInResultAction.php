<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Search\SearchResult;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Models\Person;
use App\Services\Search\LinkedInProfileSelector;
use Illuminate\Support\Str;

/**
 * Local, sync (`find_linkedin` step): who to look up next, storing a found URL with its provenance, finishing.
 */
final class ApplyLinkedInResultAction
{
    /**
     * docs/09: don't re-query the same person within 30 days.
     */
    public const RECHECK_AFTER_DAYS = 30;

    public function __construct(private readonly LinkedInProfileSelector $selector) {}

    /**
     * Founder 1, then Founder 2 — skipping anyone with a URL or searched recently.
     */
    public function nextPerson(Company $company): ?Person
    {
        return $company->founders()->get()->first(fn (Person $p): bool => $p->linkedin_url === null
            && ($p->linkedin_checked_at === null || $p->linkedin_checked_at->lessThan(now()->subDays(self::RECHECK_AFTER_DAYS))));
    }

    public function apply(Person $person, ?SearchResult $result): Person
    {
        $person->update([
            'linkedin_url' => $result === null ? null : $this->selector->profileUrl($result->url),
            'linkedin_source_url' => $result?->url,
            'linkedin_checked_at' => now(),
        ]);

        return $person;
    }

    public function finish(Company $company): Company
    {
        $founders = $company->founders()->get();

        $company->update(['pipeline_step' => PipelineStep::Qualify]);
        $company->recordEvent(PipelineStep::FindLinkedIn, PipelineOutcome::Ok, $founders->whereNotNull('linkedin_url')->count().' LinkedIn profile(s) found', [
            'searched' => $founders->whereNotNull('linkedin_checked_at')->count(),
            'found' => $founders->whereNotNull('linkedin_url')->count(),
        ]);

        return $company;
    }

    /**
     * The name people put on LinkedIn: trading name without the legal suffix ("Example Digital Ltd" → "Example Digital").
     */
    public function companyQueryName(Company $company): string
    {
        return Str::squish((string) preg_replace('/[\s,]+(?:ltd|limited|llp|plc|uk)\.?$/i', '', $company->name));
    }
}
