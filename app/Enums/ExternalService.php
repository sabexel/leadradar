<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * External systems LeadRadar calls; used for usage logging and caps.
 */
enum ExternalService: string
{
    case CompaniesHouse = 'companies_house';
    case Kvk = 'kvk';
    case OpenCorporates = 'opencorporates';
    case Reed = 'reed';
    case Adzuna = 'adzuna';
    case MillionVerifier = 'millionverifier';
    case Brave = 'brave';
    case Crawler = 'crawler';
    case GooglePlaces = 'google_places';

    public function label(): string
    {
        return match ($this) {
            self::CompaniesHouse => 'Companies House',
            self::Kvk => 'KVK',
            self::OpenCorporates => 'OpenCorporates',
            self::Reed => 'Reed',
            self::Adzuna => 'Adzuna',
            self::MillionVerifier => 'MillionVerifier',
            self::Brave => 'Brave Search',
            self::Crawler => 'Website crawler',
            self::GooglePlaces => 'Google Places',
        };
    }
}
