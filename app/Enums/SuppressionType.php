<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a suppression entry matches on.
 */
enum SuppressionType: string
{
    case Domain = 'domain';
    case Email = 'email';
    case CompanyNumber = 'company_number';
}
