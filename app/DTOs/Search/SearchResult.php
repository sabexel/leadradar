<?php

declare(strict_types=1);

namespace App\DTOs\Search;

/**
 * One web search hit.
 */
final readonly class SearchResult
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $description,
        public ?string $domain,
    ) {}

    /**
     * Canonical origin, e.g. "https://www.example.co.uk".
     */
    public function origin(): ?string
    {
        $scheme = parse_url($this->url, PHP_URL_SCHEME);
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($scheme) && is_string($host) ? strtolower($scheme).'://'.strtolower($host) : null;
    }
}
