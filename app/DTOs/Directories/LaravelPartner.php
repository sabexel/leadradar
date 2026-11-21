<?php

declare(strict_types=1);

namespace App\DTOs\Directories;

/**
 * One company listed on laravel.com/partners (P4.1). Business facts only — the directory lists no people.
 */
final readonly class LaravelPartner
{
    /**
     * @param  list<string>  $regions  region slugs, e.g. "europe", "north-america"
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $tier,
        public ?string $website,
        public array $regions,
        public string $description,
        public string $profileUrl,
    ) {}
}
