<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a company failed a hard gate (docs/06).
 */
enum RejectReason: string
{
    case NotOnRegistry = 'not_on_registry';
    case NotActive = 'not_active';
    case WrongLegalForm = 'wrong_legal_form';
    case Dormant = 'dormant';
    case Recruiter = 'recruiter';
    case Offshore = 'offshore';
    case NoWebsite = 'no_website';
    case NoValidEmail = 'no_valid_email';
    case NoLaravelEvidence = 'no_laravel_evidence';
    case Suppressed = 'suppressed';
    case BelowThreshold = 'below_threshold';

    public function label(): string
    {
        return match ($this) {
            self::NotOnRegistry => 'Not on registry',
            self::NotActive => 'Not active',
            self::WrongLegalForm => 'Wrong legal form',
            self::Dormant => 'Dormant',
            self::Recruiter => 'Recruiter',
            self::Offshore => 'Offshore',
            self::NoWebsite => 'No website',
            self::NoValidEmail => 'No valid email',
            self::NoLaravelEvidence => 'No Laravel evidence',
            self::Suppressed => 'Suppressed',
            self::BelowThreshold => 'Below threshold',
        };
    }
}
