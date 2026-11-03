<?php

declare(strict_types=1);

namespace App\DTOs\JobBoards;

use Illuminate\Support\Collection;

/**
 * One page of job-board search results.
 */
final readonly class JobSearchPage
{
    /**
     * @param  Collection<int, JobAdData>  $ads
     */
    public function __construct(
        public Collection $ads,
        public ?int $totalResults,
    ) {}

    public function isEmpty(): bool
    {
        return $this->ads->isEmpty();
    }

    public function hasAdWithinDays(int $days): bool
    {
        return $this->ads->contains(fn (JobAdData $ad): bool => $ad->isWithinDays($days));
    }
}
