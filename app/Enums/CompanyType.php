<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of business the company is.
 */
enum CompanyType: string
{
    case Agency = 'agency';
    case DirectClient = 'direct_client';
    case Recruiter = 'recruiter';
    case Offshore = 'offshore';
    case Unknown = 'unknown';
}
