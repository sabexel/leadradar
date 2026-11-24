<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Search\SearchResult;
use App\Enums\DataSource;
use App\Enums\LeadProduct;
use App\Enums\PersonRole;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Models\Person;
use App\Services\Search\LinkedInProfileSelector;

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
    /**
     * Agency and business leads without registry founders (most outside the UK) get ONE search for a
     * decision-maker (D132), once per lead.
     */
    public function needsDecisionMakerSearch(Company $company): bool
    {
        return in_array($company->lead_product, [LeadProduct::Agency, LeadProduct::Business], true)
            && filled(config('services.brave.key'))
            && ! $company->founders()->exists()
            && ! $company->pipelineEvents()->where('message', 'like', 'Decision-maker search%')->exists();
    }

    /**
     * @param  array{first_name: string, last_name: string, role: string, url: string, source_url: string}|null  $found
     */
    public function applyDecisionMaker(Company $company, ?array $found): ?Person
    {
        if ($found !== null && Person::query()->where('linkedin_url', $found['url'])->where('company_id', '!=', $company->id)->exists()) {
            $company->recordEvent(PipelineStep::FindLinkedIn, PipelineOutcome::Skipped, 'Decision-maker search: profile already belongs to another lead — ignored', ['linkedin' => $found['url']]);

            return null;
        }

        if ($found === null) {
            $company->recordEvent(PipelineStep::FindLinkedIn, PipelineOutcome::Skipped, 'Decision-maker search: nobody found');

            return null;
        }

        $person = $company->people()->create([
            'position' => 1,
            'first_name' => $found['first_name'],
            'last_name' => $found['last_name'],
            'role' => PersonRole::Founder,
            'linkedin_url' => $found['url'],
            'linkedin_source_url' => $found['source_url'],
            'linkedin_checked_at' => now(),
            'source' => DataSource::WebSearch,
            'source_url' => $found['source_url'],
        ]);

        $company->recordEvent(PipelineStep::FindLinkedIn, PipelineOutcome::Ok, "Decision-maker search: {$found['first_name']} {$found['last_name']} ({$found['role']})", [
            'role' => $found['role'],
            'linkedin' => $found['url'],
        ]);

        return $person;
    }

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
        return $company->searchName();
    }
}
