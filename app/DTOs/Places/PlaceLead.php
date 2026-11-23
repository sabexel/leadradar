<?php

declare(strict_types=1);

namespace App\DTOs\Places;

/**
 * One Google Place that may need a website (no website URI, enough reviews).
 */
final readonly class PlaceLead
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        public string $placeId,
        public string $name,
        public ?string $address,
        public ?string $locality,
        public ?string $phone,
        public ?float $rating,
        public int $reviewCount,
        public array $types,
        public ?string $mapsUrl,
        public ?string $website = null,
    ) {}
}
