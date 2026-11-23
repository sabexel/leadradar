<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a person record came from (provenance, docs/09).
 */
enum DataSource: string
{
    case CompaniesHouse = 'companies_house';
    case Kvk = 'kvk';
    case OpenCorporates = 'opencorporates';
    case Website = 'website';
    case Manual = 'manual';
}
