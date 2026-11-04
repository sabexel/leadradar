<?php

declare(strict_types=1);

namespace App\Services\Dedupe;

use Illuminate\Support\Str;

/**
 * Local: company name/domain normalisation and similarity, used for dedupe and registry matching.
 */
final class CompanyMatcher
{
    /**
     * Legal/filler suffixes removed from the end of a name (repeatedly): "Example Digital UK Ltd." → "example digital".
     */
    private const SUFFIXES = ['ltd', 'limited', 'llp', 'plc', 'lp', 'llc', 'inc', 'co', 'company', 'uk', 'gb'];

    public function normalise(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = str_replace(['&', '+'], ' and ', $name);
        $name = (string) preg_replace('/[^a-z0-9]+/', ' ', $name);

        $tokens = array_values(array_filter(explode(' ', $name), fn (string $t): bool => $t !== ''));

        if (($tokens[0] ?? null) === 'the' && count($tokens) > 1) {
            array_shift($tokens);
        }

        while (count($tokens) > 1 && in_array(end($tokens), self::SUFFIXES, true)) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }

    /**
     * Jaro-Winkler similarity of the normalised names, 0.0–1.0.
     */
    public function similarity(string $a, string $b): float
    {
        return $this->jaroWinkler($this->normalise($a), $this->normalise($b));
    }

    /**
     * Bare lowercase host without "www.", or null when the input has no usable host.
     */
    public function domainOf(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
            $url = 'https://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $host = Str::lower(rtrim($host, '.'));
        $host = (string) preg_replace('/^www\./', '', $host);

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host) === 1 ? $host : null;
    }

    private function jaroWinkler(string $a, string $b): float
    {
        if ($a === $b) {
            return $a === '' ? 0.0 : 1.0;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA === 0 || $lenB === 0) {
            return 0.0;
        }

        $window = max(0, intdiv(max($lenA, $lenB), 2) - 1);
        $matchedA = array_fill(0, $lenA, false);
        $matchedB = array_fill(0, $lenB, false);
        $matches = 0;

        for ($i = 0; $i < $lenA; $i++) {
            $from = max(0, $i - $window);
            $to = min($i + $window + 1, $lenB);

            for ($j = $from; $j < $to; $j++) {
                if (! $matchedB[$j] && $a[$i] === $b[$j]) {
                    $matchedA[$i] = $matchedB[$j] = true;
                    $matches++;
                    break;
                }
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;

        for ($i = 0; $i < $lenA; $i++) {
            if (! $matchedA[$i]) {
                continue;
            }

            while (! $matchedB[$k]) {
                $k++;
            }

            if ($a[$i] !== $b[$k]) {
                $transpositions++;
            }

            $k++;
        }

        $jaro = ($matches / $lenA + $matches / $lenB + ($matches - $transpositions / 2) / $matches) / 3;

        $prefix = 0;

        for ($i = 0; $i < min(4, $lenA, $lenB) && $a[$i] === $b[$i]; $i++) {
            $prefix++;
        }

        return round($jaro + $prefix * 0.1 * (1 - $jaro), 4);
    }
}
