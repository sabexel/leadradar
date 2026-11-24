<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\DTOs\Search\SearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Local (D132): from a `site:linkedin.com/in` search, the first profile whose title names a decision-maker role
 * (owner, founder, CEO, managing director…) at this company. Titles look like
 * "Jane Doe - Founder & CEO - Acme Digital | LinkedIn". The LinkedIn page itself is never fetched.
 */
final class DecisionMakerSelector
{
    /**
     * Place words that appear in many company names and profiles, so they never identify one company.
     */
    private const PLACES = ['dubai', 'abu', 'dhabi', 'sharjah', 'ajman', 'emirates', 'emirati', 'arabia', 'middle', 'east', 'gulf', 'london', 'manchester', 'toronto', 'sydney', 'melbourne', 'york', 'international', 'global', 'national'];

    /**
     * Degrees and honorifics after a name ("Tomislav M.D.").
     */
    private const SUFFIXES = ['md', 'm.d', 'm.d.', 'phd', 'ph.d', 'ph.d.', 'mba', 'frcs', 'frcp', 'mrcp', 'dds', 'bds', 'facs', 'cpa', 'acca', 'jr', 'sr', 'ii', 'iii'];

    private const ROLE = '/\b(owner|co-?founder|founder|ceo|chief executive|managing director|md|managing partner|general manager|director|president|principal|proprietor)\b/i';

    public function __construct(private readonly LinkedInProfileSelector $profiles) {}

    /**
     * @param  Collection<int, SearchResult>  $results
     * @return array{first_name: string, last_name: string, role: string, url: string, source_url: string}|null
     */
    public function select(string $companyName, Collection $results, ?string $location = null): ?array
    {
        $places = $this->tokens((string) $location);
        $tokens = array_values(array_diff($this->tokens($companyName), $places, self::PLACES));

        foreach ($results as $result) {
            $url = $this->profiles->profileUrl($result->url);

            if ($url === null) {
                continue;
            }

            $title = (string) preg_replace('/\s*[|·]\s*LinkedIn.*$/i', '', $result->title);
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s+[-–—]\s+/u', $title) ?: [])));

            if (count($parts) < 2) {
                continue;
            }

            $name = trim((string) preg_replace('/\p{Cf}+/u', '', $parts[0])); // invisible direction marks from RTL titles
            $rest = Str::lower(implode(' ', array_slice($parts, 1)).' '.(string) $result->description);

            if (preg_match(self::ROLE, $rest, $role) !== 1 || ! $this->mentionsCompany($rest, $tokens)) {
                continue;
            }

            $name = (string) preg_replace('/\s*,.*$/', '', $name); // "Jane Doe, MBA"
            $words = preg_split('/\s+/', (string) preg_replace('/^(?:dr|mr|mrs|ms|eng|prof)\.?\s+/i', '', $name)) ?: [];
            $words = array_values(array_filter($words, fn (string $w): bool => ! in_array(rtrim(strtolower($w), '.'), self::SUFFIXES, true) && ! in_array(strtolower($w), self::SUFFIXES, true)));

            if (count($words) < 2 || count($words) > 4 || preg_match('/\d|@/', $name) === 1) {
                continue;
            }

            return [
                'first_name' => Str::title($words[0]),
                'last_name' => Str::title((string) end($words)),
                'role' => Str::title($role[1]),
                'url' => $url,
                'source_url' => $result->url,
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $companyName): array
    {
        $words = preg_split('/[^a-z0-9]+/', Str::lower($companyName)) ?: [];
        $stop = ['ltd', 'limited', 'llc', 'fz', 'fze', 'fzco', 'dmcc', 'inc', 'the', 'and', 'company', 'co', 'group', 'uae', 'dubai', 'medical', 'center', 'centre', 'clinic', 'agency', 'digital', 'web', 'design', 'services'];

        return array_values(array_filter($words, fn (string $w): bool => strlen($w) >= 3 && ! in_array($w, $stop, true)));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function mentionsCompany(string $text, array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (preg_match('/\b'.preg_quote($token, '/').'\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
