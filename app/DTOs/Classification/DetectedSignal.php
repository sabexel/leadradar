<?php

declare(strict_types=1);

namespace App\DTOs\Classification;

use App\Enums\SignalWeight;

/**
 * One piece of evidence found by a local detector. Stored in `companies.*_evidence` as {type, weight, detail, url}.
 */
final readonly class DetectedSignal
{
    public function __construct(
        public string $type,
        public SignalWeight $weight,
        public string $detail,
        public ?string $url = null,
    ) {}

    /**
     * @return array{type: string, weight: string, detail: string, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'weight' => $this->weight->value,
            'detail' => $this->detail,
            'url' => $this->url,
        ];
    }
}
