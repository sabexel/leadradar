<?php

declare(strict_types=1);

namespace App\DTOs\CompaniesHouse;

use Carbon\CarbonImmutable;

/**
 * One hit from `GET /search/companies`.
 */
final readonly class CompanySearchResult
{
    public function __construct(
        public string $number,
        public string $title,
        public ?string $status,
        public ?string $type,
        public ?string $addressSnippet,
        public ?string $locality,
        public ?string $postcode,
        public ?CarbonImmutable $dateOfCreation,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Company facts only (no personal data) — safe to keep in a PipelineEvent for manual review.
     *
     * @return array{number: string, title: string, status: string|null, locality: string|null, similarity: float}
     */
    public function toCandidate(float $similarity): array
    {
        return [
            'number' => $this->number,
            'title' => $this->title,
            'status' => $this->status,
            'locality' => $this->locality,
            'similarity' => $similarity,
        ];
    }
}
