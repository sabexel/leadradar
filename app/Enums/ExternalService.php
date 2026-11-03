<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * External systems LeadRadar calls; used for usage logging and caps.
 */
enum ExternalService: string
{
    case CompaniesHouse = 'companies_house';
    case Reed = 'reed';
    case Adzuna = 'adzuna';
    case MillionVerifier = 'millionverifier';
    case Brave = 'brave';
    case Crawler = 'crawler';
}
