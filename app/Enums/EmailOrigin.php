<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an email address was obtained (provenance, docs/09).
 */
enum EmailOrigin: string
{
    case Crawled = 'crawled';
    case Generated = 'generated';
    case Manual = 'manual';
    case Import = 'import';
}
