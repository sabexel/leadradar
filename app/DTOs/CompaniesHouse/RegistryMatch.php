<?php

declare(strict_types=1);

namespace App\DTOs\CompaniesHouse;

use App\Enums\RegistryMatchOutcome;

final readonly class RegistryMatch
{
    /**
     * @param  list<array{number: string, title: string, status: string|null, locality: string|null, similarity: float}>  $candidates
     */
    public function __construct(
        public RegistryMatchOutcome $outcome,
        public ?CompanySearchResult $result,
        public ?float $similarity,
        public array $candidates,
    ) {}
}
