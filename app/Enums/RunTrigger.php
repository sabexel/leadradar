<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What started a discovery run.
 */
enum RunTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
}
