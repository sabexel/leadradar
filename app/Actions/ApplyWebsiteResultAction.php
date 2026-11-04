<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Search\SearchResult;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Models\Company;
use App\Services\Dedupe\CompanyMatcher;

/**
 * Local, sync: store the company website (found by search, or already known) and move on to the crawl.
 */
final class ApplyWebsiteResultAction
{
    public function __construct(private readonly CompanyMatcher $matcher) {}

    public function applySearchResult(Company $company, ?SearchResult $result): Company
    {
        if ($result === null || $result->origin() === null || $result->domain === null) {
            $company->reject(RejectReason::NoWebsite, 'No website found');

            return $company;
        }

        return $this->setWebsite($company, $result->origin(), $result->domain, ['source_url' => $result->url, 'title' => $result->title]);
    }

    /**
     * The website was known before this step (job ad, manual import) → no search needed.
     */
    public function applyKnownWebsite(Company $company): Company
    {
        $url = trim((string) $company->website);
        $url = preg_match('#^https?://#i', $url) === 1 ? $url : 'https://'.$url;
        $host = parse_url($url, PHP_URL_HOST);
        $domain = $this->matcher->domainOf($url);

        if (! is_string($host) || $domain === null) {
            $company->reject(RejectReason::NoWebsite, 'Stored website is not a valid URL', ['website' => $company->website]);

            return $company;
        }

        $origin = strtolower((string) parse_url($url, PHP_URL_SCHEME)).'://'.strtolower($host);

        return $this->setWebsite($company, $origin, $domain, ['known' => true]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function setWebsite(Company $company, string $origin, string $domain, array $context): Company
    {
        // One website = one lead. A second company claiming the same domain (e.g. a sister company)
        // would mean emailing the same people twice.
        $owner = Company::query()->where('domain', $domain)->whereKeyNot($company->id)->first();

        if ($owner !== null) {
            $company->reject(RejectReason::NoWebsite, "Website {$domain} already belongs to another lead", ['domain' => $domain, 'company_id' => $owner->id]);

            return $company;
        }

        $company->update([
            'website' => $origin,
            'domain' => $domain,
            'pipeline_step' => PipelineStep::Crawl,
        ]);

        $company->recordEvent(PipelineStep::FindWebsite, PipelineOutcome::Ok, "Website {$domain}", ['domain' => $domain, ...$context]);

        return $company;
    }
}
