<?php

declare(strict_types=1);

namespace App\DTOs\Scoring;

use App\Enums\RejectReason;

/**
 * Result of the docs/06 gates + 0–10 score.
 */
final readonly class LeadScore
{
    /**
     * @param  array<string, array{points: float, max: float, items: array<string, float>, days_old?: int|null}>  $breakdown
     */
    public function __construct(
        public float $total,
        public array $breakdown,
        public ?RejectReason $failedGate,
    ) {}

    public function passesGates(): bool
    {
        return $this->failedGate === null;
    }

    /**
     * @return array<string, mixed> stored in `companies.score_breakdown`
     */
    public function toArray(): array
    {
        return [...$this->breakdown, 'total' => $this->total, 'failed_gate' => $this->failedGate?->value];
    }
}
