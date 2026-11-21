<?php

declare(strict_types=1);

namespace App\DTOs\CompaniesHouse;

use Carbon\CarbonImmutable;

/**
 * One company from the Companies House advanced search (SIC code + location, docs/04).
 */
final readonly class RegistryCompany
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
        public ?CarbonImmutable $dateOfCreation,
        public ?string $locality,
        public array $registeredAddress,
    ) {}
}
