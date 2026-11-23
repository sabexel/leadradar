<?php

declare(strict_types=1);

namespace App\DTOs\Places;

use Illuminate\Support\Collection;

final readonly class PlaceSearchPage
{
    /**
     * @param  Collection<int, PlaceLead>  $places
     */
    public function __construct(
        public Collection $places,
        public ?string $nextPageToken,
    ) {}
}
