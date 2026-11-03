<?php

declare(strict_types=1);

namespace App\DTOs\JobBoards;

use App\Enums\SignalSource;
use Carbon\CarbonImmutable;

/**
 * One job ad from any job board (Reed, Adzuna). Descriptions are plain text.
 */
final readonly class JobAdData
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public SignalSource $source,
        public string $externalId,
        public string $title,
        public string $employerName,
        public ?string $employerExternalId,
        public ?string $location,
        public ?string $description,
        public ?string $url,
        public ?string $externalUrl,
        public ?CarbonImmutable $postedAt,
        public array $raw,
    ) {}

    public function isWithinDays(int $days): bool
    {
        return $this->postedAt !== null && $this->postedAt->greaterThanOrEqualTo(now()->subDays($days)->startOfDay());
    }
}
