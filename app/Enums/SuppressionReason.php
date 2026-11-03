<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why something is suppressed.
 */
enum SuppressionReason: string
{
    case AlreadyContacted = 'already_contacted';
    case OptedOut = 'opted_out';
    case NotInterested = 'not_interested';
    case Competitor = 'competitor';
    case Manual = 'manual';
}
