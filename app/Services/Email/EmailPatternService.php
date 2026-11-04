<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Person;
use Illuminate\Support\Str;

/**
 * Local: likely email addresses for a founder (docs/04).
 *
 * Patterns, in the order they are tried: first@, first.last@, firstlast@, f.last@, flast@, first_last@, last@.
 * A pattern already confirmed for someone else at the company goes first.
 */
final class EmailPatternService
{
    public const PATTERNS = ['first', 'first.last', 'firstlast', 'f.last', 'flast', 'first_last', 'last'];

    /**
     * @param  string|null  $preferredPattern  one of PATTERNS, e.g. confirmed for a colleague
     * @return list<string>
     */
    public function candidates(Person $person, string $domain, ?string $preferredPattern = null): array
    {
        $parts = $this->nameParts($person);

        if ($parts === null) {
            return [];
        }

        $order = self::PATTERNS;

        if ($preferredPattern !== null && in_array($preferredPattern, self::PATTERNS, true)) {
            $order = [$preferredPattern, ...array_values(array_diff(self::PATTERNS, [$preferredPattern]))];
        }

        $domain = Str::lower((string) preg_replace('/^www\./', '', trim($domain)));
        $emails = [];

        foreach ($order as $pattern) {
            $emails[] = $this->localPart($pattern, $parts['first'], $parts['last']).'@'.$domain;
        }

        return array_values(array_unique($emails));
    }

    /**
     * Which pattern an address follows for this person, if any (used to learn a company's convention).
     */
    public function patternOf(Person $person, string $email): ?string
    {
        $parts = $this->nameParts($person);

        if ($parts === null || ! str_contains($email, '@')) {
            return null;
        }

        $local = Str::lower(explode('@', $email)[0]);

        foreach (self::PATTERNS as $pattern) {
            if ($this->localPart($pattern, $parts['first'], $parts['last']) === $local) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Lowercase ASCII letters only: "José" → "jose", "O'Example-Smith" → "oexamplesmith".
     * Uses the preferred name ("Josh") when one was set by hand.
     *
     * @return array{first: string, last: string}|null
     */
    private function nameParts(Person $person): ?array
    {
        $clean = fn (?string $name): string => (string) preg_replace('/[^a-z]/', '', Str::lower(Str::ascii((string) $name)));

        $first = $clean($person->preferred_name ?: $person->first_name);
        $last = $clean($person->last_name);

        return $first === '' || $last === '' ? null : ['first' => $first, 'last' => $last];
    }

    private function localPart(string $pattern, string $first, string $last): string
    {
        return match ($pattern) {
            'first' => $first,
            'first.last' => "{$first}.{$last}",
            'firstlast' => "{$first}{$last}",
            'f.last' => "{$first[0]}.{$last}",
            'flast' => "{$first[0]}{$last}",
            'first_last' => "{$first}_{$last}",
            'last' => $last,
            default => $first,
        };
    }
}
