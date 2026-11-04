<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How much a detected signal (Laravel or offshore evidence) counts.
 */
enum SignalWeight: string
{
    case Strong = 'strong';
    case Medium = 'medium';
    case Weak = 'weak';
}
