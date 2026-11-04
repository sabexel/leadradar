<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\DTOs\Classification\DetectedSignal;
use App\Enums\SignalWeight;
use Illuminate\Support\Str;

/**
 * Local: spots offshore development shops (competitors, never leads) — docs/04.
 *
 * Text part (job ads now, crawled pages from P1.7):
 * - offshore country + outsourcing language in the same text → medium `offshore_office`
 * - outsourcing language alone → weak `outsourcing_language`
 * - "hire a developer from $X/hour" pricing → strong `hourly_rate_pricing`
 * Officer part: every officer with a known residence lives outside the UK → medium `officers_abroad`,
 * strong when combined with any text signal (see `combine()`).
 */
final class OffshoreSignalDetector
{
    private const COUNTRIES = [
        'india', 'pakistan', 'bangladesh', 'sri lanka', 'nepal', 'ukraine', 'belarus', 'philippines',
        'vietnam', 'egypt', 'nigeria', 'kenya',
        // Common delivery-centre cities, so "our team in Lahore" counts too.
        'bangalore', 'bengaluru', 'pune', 'hyderabad', 'chennai', 'noida', 'gurgaon', 'gurugram', 'ahmedabad',
        'mumbai', 'kolkata', 'jaipur', 'lahore', 'karachi', 'islamabad', 'dhaka', 'colombo', 'kyiv', 'kiev',
        'lviv', 'kharkiv',
    ];

    private const OUTSOURCING_PHRASES = [
        'offshore', 'off-shore', 'nearshore', 'near-shore', 'delivery cent(?:re|er)', 'development cent(?:re|er)',
        'dedicated (?:laravel |php )?developers?', 'hire (?:a |an |dedicated )?(?:laravel |php )?developers?',
        'staff augmentation', 'team augmentation', 'resource augmentation', 'outsourc\w*',
    ];

    private const UK_RESIDENCES = ['england', 'scotland', 'wales', 'northern ireland', 'united kingdom', 'uk', 'great britain', 'gb'];

    /**
     * @return list<DetectedSignal>
     */
    public function detectInText(string $text, ?string $url = null): array
    {
        $text = Str::lower(Str::squish($text));

        if ($text === '') {
            return [];
        }

        $signals = [];
        $phrase = $this->firstMatch($text, self::OUTSOURCING_PHRASES);
        $country = $this->firstMatch($text, array_map(fn (string $c): string => preg_quote($c, '/'), self::COUNTRIES));

        if ($phrase !== null && $country !== null) {
            $signals[] = new DetectedSignal('offshore_office', SignalWeight::Medium, "\"{$phrase}\" with \"{$country}\"", $url);
        } elseif ($phrase !== null) {
            $signals[] = new DetectedSignal('outsourcing_language', SignalWeight::Weak, "\"{$phrase}\"", $url);
        }

        // "Hire a Laravel developer from $15/hour", "dedicated developers at £12 per hour".
        if (preg_match('/(?:hire|dedicated|developers?)[^.]{0,60}?(?:from|at|starting|only)?\s*[$£€]\s?\d{1,3}(?:\.\d{1,2})?\s*(?:\/|per\s+)\s*(?:hour|hr|h)\b/u', $text, $m) === 1) {
            $signals[] = new DetectedSignal('hourly_rate_pricing', SignalWeight::Strong, Str::limit(trim($m[0]), 80), $url);
        }

        return $signals;
    }

    /**
     * @param  list<string|null>  $countriesOfResidence
     */
    public function detectOfficersAbroad(array $countriesOfResidence): ?DetectedSignal
    {
        $known = array_values(array_filter(
            array_map(fn (?string $c): string => Str::lower(trim((string) $c)), $countriesOfResidence),
            fn (string $c): bool => $c !== '',
        ));

        if ($known === [] || array_intersect($known, self::UK_RESIDENCES) !== []) {
            return null;
        }

        return new DetectedSignal('officers_abroad', SignalWeight::Medium, 'All officers live outside the UK: '.implode(', ', array_unique($known)));
    }

    /**
     * Officers abroad + any text signal → strong (docs/04).
     *
     * @param  list<DetectedSignal>  $textSignals
     * @return list<DetectedSignal>
     */
    public function combine(array $textSignals, ?DetectedSignal $officersAbroad): array
    {
        if ($officersAbroad === null) {
            return $textSignals;
        }

        if ($textSignals === []) {
            return [$officersAbroad];
        }

        return [...$textSignals, new DetectedSignal('officers_abroad', SignalWeight::Strong, $officersAbroad->detail.' (plus offshore language)')];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match('/\b'.$pattern.'\b/u', $text, $m) === 1) {
                return $m[0];
            }
        }

        return null;
    }
}
