<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a signal (reason we found the company) came from.
 */
enum SignalSource: string
{
    case Reed = 'reed';
    case Adzuna = 'adzuna';
    case Manual = 'manual';
    case Import = 'import';
    case LaravelPartners = 'laravel_partners';

    public function label(): string
    {
        return match ($this) {
            self::Reed => 'Reed',
            self::Adzuna => 'Adzuna',
            self::Manual => 'Manual',
            self::Import => 'Import',
            self::LaravelPartners => 'Laravel Partners',
        };
    }
}
