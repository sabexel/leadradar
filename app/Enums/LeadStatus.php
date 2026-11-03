<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Overall lifecycle of a company/lead (docs/03).
 */
enum LeadStatus: string
{
    case Discovered = 'discovered';
    case Enriching = 'enriching';
    case Qualified = 'qualified';
    case Rejected = 'rejected';
    case Suppressed = 'suppressed';
    case Error = 'error';
}
