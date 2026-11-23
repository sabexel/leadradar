<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTOs\Search\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Local: from one agency search (D113), keep results that are an agency's OWN site — not directories, "top 10"
 * lists, platforms, marketplaces or sites on another country's domain — and give each a company name.
 */
final class AgencyResultSelector
{
    /**
     * Country-code TLDs by market. A result on a ccTLD outside its market is skipped (e.g. a ".in" site in a UK
     * search is an offshore shop, D113); generic TLDs (.com, .agency, .io…) are always allowed.
     */
    private const MARKET_TLDS = [
        'GB' => ['uk'], 'US' => ['us'], 'CA' => ['ca'], 'AU' => ['au'], 'NZ' => ['nz'], 'IE' => ['ie'],
        'NL' => ['nl'], 'DE' => ['de'], 'BE' => ['be'], 'DK' => ['dk'], 'SE' => ['se'], 'NO' => ['no'],
        'FI' => ['fi'], 'AE' => ['ae'], 'AT' => ['at'], 'CH' => ['ch'], 'FR' => ['fr'], 'ES' => ['es'], 'IT' => ['it'],
        'PT' => ['pt'], 'PL' => ['pl'], 'LU' => ['lu'],
    ];

    /**
     * Titles of listicles and directory pages, not agencies.
     */
    private const LIST_TITLE = '/\b(?:top\s*\d+|\d+\s+best|best\s+\d+|\d+\+?\s+(?:top|leading)|best\s+(?:web|digital|wordpress|shopify|seo)|list of|near me|directory|reviews? of|rankings?|compare|vs\.?)\b/i';

    public function __construct(private readonly WebsiteSelector $websites) {}

    /**
     * @param  Collection<int, SearchResult>  $results
     * @return list<array{name: string, domain: string, url: string, title: string}>
     */
    public function select(Collection $results, string $country): array
    {
        $picked = [];

        foreach ($results as $result) {
            $domain = $result->domain;

            if ($domain === null || isset($picked[$domain]) || $this->isExcluded($domain, $country)) {
                continue;
            }

            if (preg_match(self::LIST_TITLE, $result->title) === 1 || ! $this->isShallow($result->url)) {
                continue;
            }

            $picked[$domain] = [
                'name' => $this->nameFor($result->title, $domain),
                'domain' => $domain,
                'url' => 'https://'.$domain,
                'title' => $result->title,
            ];
        }

        return array_values($picked);
    }

    private function isExcluded(string $domain, string $country): bool
    {
        if ($this->websites->isBlocked($domain)) {
            return true;
        }

        foreach ((array) config('services.brave.agency_blocked_domains', []) as $blocked) {
            $blocked = Str::lower((string) $blocked);

            if ($domain === $blocked || str_ends_with($domain, '.'.$blocked)) {
                return true;
            }
        }

        $tld = Str::afterLast($domain, '.');

        if (strlen($tld) !== 2) {
            return false; // generic TLD
        }

        return ! in_array($tld, self::MARKET_TLDS[strtoupper($country)] ?? [], true) && $tld !== 'io' && $tld !== 'co';
    }

    /**
     * Home page or one level down ("/", "/en", "/about", "/services") — deep pages are articles and listings.
     */
    private function isShallow(string $url): bool
    {
        if ($this->websites->isHomePage($url)) {
            return true;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === '' || (! str_contains($path, '/') && strlen($path) <= 24 && parse_url($url, PHP_URL_QUERY) === null);
    }

    /**
     * "Acme Digital | Shopify Agency in Leeds" → "Acme Digital": the title part that shares a word with the domain,
     * else the first part; a title without one falls back to the domain label.
     */
    private function nameFor(string $title, string $domain): string
    {
        $label = Str::lower(Str::before($domain, '.'));
        $parts = array_values(array_filter(array_map(
            fn (string $p): string => Str::squish($p),
            preg_split('/\s+[|\-–—:·•]\s+|\s*\|\s*/u', $title) ?: [$title],
        ), fn (string $p): bool => $p !== '' && mb_strlen($p) <= 60));

        foreach ($parts as $part) {
            $compact = Str::lower((string) preg_replace('/[^a-z0-9]/i', '', $part));

            foreach (preg_split('/\s+/', Str::lower($part)) ?: [] as $word) {
                $word = (string) preg_replace('/[^a-z0-9]/', '', $word);

                if (strlen($word) >= 3 && str_contains($label, $word)) {
                    return $part;
                }
            }

            if ($compact !== '' && str_contains($compact, $label)) {
                return $part;
            }
        }

        return Str::of($label)->replace(['-', '_'], ' ')->title()->toString();
    }
}
