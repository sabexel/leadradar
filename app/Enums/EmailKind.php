<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an email address is used for.
 */
enum EmailKind: string
{
    case Contact = 'contact';
    case Careers = 'careers';
    case Person = 'person';
    case Other = 'other';
}
