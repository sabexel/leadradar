<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTOs\Search\SearchResult;
use App\Enums\ExternalService;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Support\ApiUsageLimiter;
use App\Services\Support\ExternalApiService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Brave Search web API (https://api-dashboard.search.brave.com/app/documentation/web-search).
 * Auth: `X-Subscription-Token` header. Paid per query → daily cap `cap.brave.per_day`.
 * The ONLY way LeadRadar finds websites and LinkedIn URLs — no Google scraping, no LinkedIn fetching.
 */
final class BraveSearchService extends ExternalApiService
{
    /**
     * Brave's search markets (`country`); others (AE, IE…) search ALL with the city in the query (D127).
     */
    private const MARKETS = ['AR', 'AU', 'AT', 'BE', 'BR', 'CA', 'CL', 'DK', 'FI', 'FR', 'DE', 'HK', 'IN', 'ID', 'IT', 'JP', 'KR', 'MY', 'MX', 'NL', 'NZ', 'NO', 'CN', 'PL', 'PT', 'PH', 'RU', 'SA', 'ZA', 'ES', 'SE', 'CH', 'TW', 'TR', 'GB', 'US'];

    public function __construct(
        ApiUsageLimiter $limiter,
        private readonly CompanyMatcher $matcher,
        private readonly WebsiteSelector $websites,
        private readonly LinkedInProfileSelector $linkedIn,
    ) {
        parent::__construct($limiter);
    }

    protected function service(): ExternalService
    {
        return ExternalService::Brave;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.brave.base_url'))
            ->withHeaders(['X-Subscription-Token' => $this->requireConfig('key')])
            ->acceptJson()
            ->timeout((int) config('services.brave.timeout', 15));
    }

    /**
     * One query for agencies in a market (D113): e.g. "shopify agency Toronto". Results are raw — the caller keeps
     * only agency home pages (AgencyResultSelector). Country is Brave's 2-letter market code.
     *
     * @return Collection<int, SearchResult>
     */
    public function searchAgencies(string $query, string $country, int $offset = 0): Collection
    {
        $country = strtoupper($country);

        return $this->search(Str::squish($query), in_array($country, self::MARKETS, true) ? $country : 'ALL', $offset);
    }

    /**
     * One query: `"{name}"`, UK results. Pass the name without its legal suffix (`Company::searchName()`):
     * "Ltd" in the phrase, or a town from the job ad, steers the results to company-data directories (D104).
     * Null when no result looks like the company's own site.
     */
    public function findCompanyWebsite(string $name): ?SearchResult
    {
        return $this->websites->select($name, $this->search('"'.Str::squish($name).'"'));
    }

    /**
     * One query: `site:linkedin.com/in "{full name}" "{company}"`. Returns the matching search result
     * (its `url` is the raw result URL); the LinkedIn page is never requested.
     */
    public function findLinkedInProfile(string $firstName, string $lastName, string $companyName): ?SearchResult
    {
        $query = sprintf('site:linkedin.com/in "%s %s" "%s"', Str::squish($firstName), Str::squish($lastName), Str::squish($companyName));

        return $this->linkedIn->select($firstName, $lastName, $this->search($query));
    }

    /**
     * @return Collection<int, SearchResult>
     */
    private function search(string $query, string $country = 'GB', int $offset = 0): Collection
    {
        $data = $this->json($this->get('/web/search', [
            'q' => $query,
            'country' => $country,
            'offset' => max(0, min(9, $offset)),
            'count' => min(20, max(1, (int) config('services.brave.results_per_query', 10))),
            'safesearch' => 'moderate',
        ], '/web/search'), '/web/search');

        $results = is_array($data['web'] ?? null) && is_array($data['web']['results'] ?? null) ? $data['web']['results'] : [];

        return collect($results)
            ->filter(fn (mixed $row): bool => is_array($row) && is_string($row['url'] ?? null) && is_string($row['title'] ?? null))
            ->map(fn (array $row): SearchResult => new SearchResult(
                title: Str::squish(html_entity_decode(strip_tags((string) $row['title']), ENT_QUOTES | ENT_HTML5)),
                url: (string) $row['url'],
                description: is_string($row['description'] ?? null)
                    ? Str::squish(html_entity_decode(strip_tags($row['description']), ENT_QUOTES | ENT_HTML5))
                    : null,
                domain: $this->matcher->domainOf((string) $row['url']),
            ))
            ->values();
    }
}
