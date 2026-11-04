<?php

declare(strict_types=1);

namespace App\Services\CompaniesHouse;

use App\DTOs\CompaniesHouse\CompanySearchResult;
use App\DTOs\CompaniesHouse\RegistryMatch;
use App\Enums\RegistryMatchOutcome;
use App\Services\Dedupe\CompanyMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Local: picks the registry entry behind a company name (docs/04 Companies House rules).
 *
 * 1. Candidates = search hits with normalised-name similarity ≥ 0.85.
 * 2. Prefer active ones; if only inactive candidates exist → Inactive (rejected as not_active, no further calls).
 * 3. Active candidates within TIE_MARGIN of the best are a tie; break it with the signal's town.
 * 4. Still more than one → Ambiguous (rejected not_on_registry, candidates logged for manual review).
 */
final class RegistryMatcher
{
    public const THRESHOLD = 0.85;

    public const TIE_MARGIN = 0.02;

    public function __construct(private readonly CompanyMatcher $matcher) {}

    /**
     * @param  Collection<int, CompanySearchResult>  $results
     */
    public function select(string $name, ?string $location, Collection $results): RegistryMatch
    {
        $scored = $results
            ->map(fn (CompanySearchResult $r): array => ['result' => $r, 'similarity' => $this->matcher->similarity($name, $r->title)])
            ->filter(fn (array $c): bool => $c['similarity'] >= self::THRESHOLD)
            ->sortByDesc('similarity')
            ->values();

        $candidates = array_values($scored
            ->map(fn (array $c): array => $c['result']->toCandidate($c['similarity']))
            ->all());

        if ($scored->isEmpty()) {
            return new RegistryMatch(RegistryMatchOutcome::NotFound, null, null, $this->summarise($name, $results));
        }

        $active = $scored->filter(fn (array $c): bool => $c['result']->isActive())->values();

        if ($active->isEmpty()) {
            $best = $scored->first();

            return new RegistryMatch(RegistryMatchOutcome::Inactive, $best['result'], $best['similarity'], $candidates);
        }

        $bestScore = $active->first()['similarity'];
        $top = $active->filter(fn (array $c): bool => $c['similarity'] >= $bestScore - self::TIE_MARGIN)->values();

        if ($top->count() > 1) {
            $top = $this->preferLocation($top, $location);
        }

        if ($top->count() === 1) {
            return new RegistryMatch(RegistryMatchOutcome::Matched, $top->first()['result'], $top->first()['similarity'], $candidates);
        }

        return new RegistryMatch(RegistryMatchOutcome::Ambiguous, null, null, $candidates);
    }

    /**
     * Keep only candidates whose address mentions the signal's town (first part of e.g. "Brighton, East Sussex").
     * Returns the input unchanged when the town is unknown or matches none.
     *
     * @param  Collection<int, array{result: CompanySearchResult, similarity: float}>  $top
     * @return Collection<int, array{result: CompanySearchResult, similarity: float}>
     */
    private function preferLocation(Collection $top, ?string $location): Collection
    {
        $town = Str::lower(trim(explode(',', (string) $location)[0]));

        if ($town === '') {
            return $top;
        }

        $local = $top->filter(function (array $c) use ($town): bool {
            $address = Str::lower(($c['result']->locality ?? '').' '.($c['result']->addressSnippet ?? ''));

            return str_contains($address, $town);
        })->values();

        return $local->isEmpty() ? $top : $local;
    }

    /**
     * Below-threshold hits, kept for the event context so a human can see what was considered.
     *
     * @param  Collection<int, CompanySearchResult>  $results
     * @return list<array{number: string, title: string, status: string|null, locality: string|null, similarity: float}>
     */
    private function summarise(string $name, Collection $results): array
    {
        return array_values($results
            ->take(5)
            ->map(fn (CompanySearchResult $r): array => $r->toCandidate($this->matcher->similarity($name, $r->title)))
            ->all());
    }
}
