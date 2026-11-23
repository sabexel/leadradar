<?php

declare(strict_types=1);

namespace App\DTOs\Registries;

use App\DTOs\CompaniesHouse\CompanyProfile;
use App\DTOs\CompaniesHouse\Officer;
use Illuminate\Support\Collection;

/**
 * One registry call that returns the profile and the officers together (OpenCorporates, D110).
 */
final readonly class RegistryCompanyDetails
{
    /**
     * @param  Collection<int, Officer>  $officers
     */
    public function __construct(
        public CompanyProfile $profile,
        public Collection $officers,
    ) {}
}
