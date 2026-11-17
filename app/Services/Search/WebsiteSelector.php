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
 * Blocked domains (directories, socials, job sites) never count. Then, in order of preference:
 * 1. the first result whose domain contains a significant token of the company name;
 * 2. the first result that is a site's home page and has a significant token as a whole word in its title.
 * A deep page that merely mentions the company is never picked: company-data directories (hundreds of them,
 * too many to block) list every company on a page titled with its name (D102).
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

        $candidates = $results->filter(fn (SearchResult $r): bool => $r->domain !== null && ! $this->isBlocked($r->domain));

        return $candidates->first(fn (SearchResult $r): bool => $this->domainMatches((string) $r->domain, $tokens))
            ?? $candidates->first(fn (SearchResult $r): bool => $this->isHomePage($r->url) && $this->titleMatches($r->title, $tokens));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function domainMatches(string $domain, array $tokens): bool
    {
        $compact = str_replace(['-', '.'], '', $domain);

        foreach ($tokens as $token) {
            if (str_contains($compact, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function titleMatches(string $title, array $tokens): bool
    {
        $title = Str::lower(Str::ascii($title));

        foreach ($tokens as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/', $title) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isHomePage(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return parse_url($url, PHP_URL_QUERY) === null
            && preg_match('#^/?((index|home)(\.[a-z]+)?|en(-gb)?/?)?$#i', $path) === 1;
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
