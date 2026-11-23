<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The company register used for each target country (docs/04, D110). Stored in `companies.registry`.
 */
enum Registry: string
{
    case CompaniesHouse = 'companies_house';
    case Kvk = 'kvk';
    case OpenCorporates = 'opencorporates';

    public static function forCountry(?string $country): ?self
    {
        return match ($country) {
            'GB' => self::CompaniesHouse,
            'NL' => self::Kvk,
            'US' => self::OpenCorporates,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function countries(): array
    {
        return array_map(fn (self $r): string => $r->country(), self::cases());
    }

    public function country(): string
    {
        return match ($this) {
            self::CompaniesHouse => 'GB',
            self::Kvk => 'NL',
            self::OpenCorporates => 'US',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CompaniesHouse => 'Companies House',
            self::Kvk => 'KVK',
            self::OpenCorporates => 'OpenCorporates',
        };
    }

    /**
     * Legal forms that are companies, not sole traders or partnerships of individuals (docs/09).
     * OpenCorporates often has no type for US records, so `unknown` passes there.
     *
     * @return list<string>
     */
    public function allowedForms(): array
    {
        return match ($this) {
            self::CompaniesHouse => ['ltd', 'llp', 'plc'],
            self::Kvk => ['bv', 'nv'],
            self::OpenCorporates => ['llc', 'corporation', 'unknown'],
        };
    }

    public function dataSource(): DataSource
    {
        return match ($this) {
            self::CompaniesHouse => DataSource::CompaniesHouse,
            self::Kvk => DataSource::Kvk,
            self::OpenCorporates => DataSource::OpenCorporates,
        };
    }

    public function companyUrl(string $number): string
    {
        return match ($this) {
            self::CompaniesHouse => "https://find-and-update.company-information.service.gov.uk/company/{$number}",
            self::Kvk => 'https://www.kvk.nl/zoeken/?source=all&q='.rawurlencode($number),
            self::OpenCorporates => "https://opencorporates.com/companies/{$number}",
        };
    }

    public function officersUrl(string $number): string
    {
        return $this === self::CompaniesHouse ? $this->companyUrl($number).'/officers' : $this->companyUrl($number);
    }
}
