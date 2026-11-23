<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which Webwayout offer a lead is for (D111). Laravel agency/capacity leads stay on the full pipeline;
 * website leads come from Google Places and skip Laravel enrichment.
 */
enum LeadProduct: string
{
    case Laravel = 'laravel';
    case Website = 'website';
    case Agency = 'agency';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Website => 'Needs a website',
            self::Agency => 'Agency (any stack)',
        };
    }
}
