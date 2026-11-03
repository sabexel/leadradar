<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Manual outreach progress, set from the dashboard.
 */
enum OutreachStatus: string
{
    case NotContacted = 'not_contacted';
    case Contacted = 'contacted';
    case Replied = 'replied';
    case NotInterested = 'not_interested';
    case Client = 'client';
}
