<?php

declare(strict_types=1);

namespace App\DTOs\CompaniesHouse;

use Carbon\CarbonImmutable;

/**
 * `GET /company/{number}` — registry facts used by the qualification gates.
 */
final readonly class CompanyProfile
{
    /**
     * @param  list<string>  $sicCodes
     * @param  array<string, string>  $registeredAddress
     */
    public function __construct(
        public string $number,
        public string $name,
        public ?string $status,
        public ?string $type,
        public array $sicCodes,
        public array $registeredAddress,
        public ?string $lastAccountsType,
        public ?CarbonImmutable $dateOfCreation,
        public bool $hasBeenLiquidated,
        public bool $hasInsolvencyHistory,
    ) {}

    public function locality(): ?string
    {
        return $this->registeredAddress['locality'] ?? null;
    }
}
