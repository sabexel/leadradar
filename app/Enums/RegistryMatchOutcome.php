<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of matching a company against registry search results.
 */
enum RegistryMatchOutcome: string
{
    case Matched = 'matched';
    case NotFound = 'not_found';
    case Ambiguous = 'ambiguous';
    case Inactive = 'inactive';
}
