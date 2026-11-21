<?php

declare(strict_types=1);

namespace App\DTOs\CompaniesHouse;

use Illuminate\Support\Collection;

final readonly class RegistrySearchPage
{
    /**
     * @param  Collection<int, RegistryCompany>  $companies
     */
    public function __construct(
        public Collection $companies,
        public int $hits,
    ) {}
}
