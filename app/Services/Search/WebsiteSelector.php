<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTOs\Search\SearchResult;
use App\Services\Dedupe\CompanyMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Local: picks a company's own website from search results (docs/04).
 *
 * First result whose domain is not a directory/social/job site AND whose domain, title or snippet
 * contains a significant token of the company name.
 */
final class WebsiteSelector
{
    /**
     * Words too common in company names to identify one.
     */
    private const GENERIC_TOKENS = [
        'and', 'the', 'of', 'digital', 'studio', 'studios', 'web', 'labs', 'lab', 'software', 'solutions',
        'services', 'group', 'holdings', 'consulting', 'consultancy', 'agency', 'media', 'design', 'creative',
        'technologies', 'technology', 'tech', 'systems', 'development', 'developers', 'interactive', 'online',
        'global', 'international', 'partners', 'ventures', 'uk', 'co', 'it',
    ];

    public function __construct(private readonly CompanyMatcher $matcher) {}

    /**
     * @param  Collection<int, SearchResult>  $results
     */
    public function select(string $companyName, Collection $results): ?SearchResult
    {
        $tokens = $this->significantTokens($companyName);

        if ($tokens === []) {
            return null;
        }

        return $results->first(function (SearchResult $result) use ($tokens): bool {
            if ($result->domain === null || $this->isBlocked($result->domain)) {
                return false;
            }

            $haystack = Str::lower(Str::ascii($result->domain.' '.$result->title.' '.($result->description ?? '')));
            $compactDomain = str_replace(['-', '.'], '', $result->domain);

            foreach ($tokens as $token) {
                if (preg_match('/\b'.preg_quote($token, '/').'\b/', $haystack) === 1 || str_contains($compactDomain, $token)) {
                    return true;
                }
            }

            return false;
        });
    }

    public function isBlocked(string $domain): bool
    {
        foreach ((array) config('services.brave.blocked_domains', []) as $blocked) {
            $blocked = Str::lower((string) $blocked);

            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        // Government and registry sites are never a company's own website.
        return str_ends_with($domain, '.gov.uk');
    }

    /**
     * Distinctive words of the name (≥ 3 letters, not generic); falls back to all words when every one is generic.
     *
     * @return list<string>
     */
    public function significantTokens(string $companyName): array
    {
        $tokens = array_values(array_filter(explode(' ', $this->matcher->normalise($companyName)), fn (string $t): bool => strlen($t) >= 3));
        $significant = array_values(array_diff($tokens, self::GENERIC_TOKENS));

        return $significant !== [] ? $significant : $tokens;
    }
}
