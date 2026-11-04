<?php

declare(strict_types=1);

namespace App\DTOs\CompaniesHouse;

use Carbon\CarbonImmutable;

/**
 * One officer from `GET /company/{number}/officers`.
 * Deliberately has no date of birth, nationality or address — never stored (docs/09).
 */
final readonly class Officer
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $role,
        public ?CarbonImmutable $appointedOn,
        public ?CarbonImmutable $resignedOn,
        public ?string $countryOfResidence,
    ) {}

    public function isActive(): bool
    {
        return $this->resignedOn === null;
    }

    /**
     * Natural-person directors and LLP members. Secretaries and corporate officers are not founders.
     */
    public function isFounderRole(): bool
    {
        return in_array($this->role, ['director', 'llp-member', 'llp-designated-member'], true);
    }
}
