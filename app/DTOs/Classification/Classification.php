<?php

declare(strict_types=1);

namespace App\DTOs\Classification;

use App\Enums\CompanyType;

final readonly class Classification
{
    /**
     * @param  list<DetectedSignal>  $offshoreSignals
     * @param  list<string>  $reasons  short human-readable explanation for the timeline
     */
    public function __construct(
        public CompanyType $type,
        public array $offshoreSignals,
        public array $reasons,
    ) {}
}
