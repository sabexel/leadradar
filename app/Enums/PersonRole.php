<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Registry role of a founder/director.
 */
enum PersonRole: string
{
    case Director = 'director';
    case LlpMember = 'llp-member';
    case Founder = 'founder';
}
