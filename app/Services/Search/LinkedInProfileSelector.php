<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTOs\Search\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Local: picks a person's LinkedIn profile URL from search results (docs/04). The page itself is never fetched.
 *
 * Accepts only `linkedin.com/in/{slug}` (any country subdomain) whose result title contains BOTH the first and
 * the last name as whole words.
 */
final class LinkedInProfileSelector
{
    /**
     * @param  Collection<int, SearchResult>  $results
     */
    public function select(string $firstName, string $lastName, Collection $results): ?SearchResult
    {
        $first = $this->words($firstName);
        $last = $this->words($lastName);

        if ($first === [] || $last === []) {
            return null;
        }

        return $results->first(function (SearchResult $result) use ($first, $last): bool {
            if ($this->profileUrl($result->url) === null) {
                return false;
            }

            // Titles look like "Jane Example – Founder – Example Digital | LinkedIn": only the part before the
            // first spaced separator is the person's name (the company name may contain their surname).
            $namePart = preg_split('/\s[-–—|·:]\s|\s+on LinkedIn\b/u', $result->title)[0] ?? '';
            $title = ' '.implode(' ', $this->words($namePart)).' ';

            // First given name, and the full surname (multi-part surnames must appear together).
            return str_contains($title, " {$first[0]} ")
                && str_contains($title, ' '.implode(' ', $last).' ');
        });
    }

    /**
     * Canonical profile URL ("https://www.linkedin.com/in/jane-example"), or null when the URL is not a profile.
     */
    public function profileUrl(string $url): ?string
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host !== 'linkedin.com' && ! str_ends_with($host, '.linkedin.com')) {
            return null;
        }

        if (preg_match('#^/in/([A-Za-z0-9\-_%]+)/?$#', $path, $m) !== 1) {
            return null;
        }

        return 'https://www.linkedin.com/in/'.Str::lower(rawurldecode($m[1]));
    }

    /**
     * Lowercase ASCII words: "Jane O'Example-Smith | LinkedIn" → [jane, oexample, smith, linkedin].
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $text = Str::lower(Str::ascii(str_replace("'", '', $text)));

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $text) ?: [], fn (string $w): bool => $w !== ''));
    }
}
