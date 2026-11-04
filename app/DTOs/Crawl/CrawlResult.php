<?php

declare(strict_types=1);

namespace App\DTOs\Crawl;

use App\DTOs\Classification\DetectedSignal;
use App\Enums\PageType;

/**
 * Facts extracted from ONE fetched page. Holds no HTML or page text (docs/03, docs/09).
 */
final readonly class CrawlResult
{
    /**
     * @param  list<ExtractedEmail>  $emails
     * @param  list<string>  $links  same-domain candidate URLs, best first
     * @param  list<DetectedSignal>  $laravelSignals
     * @param  list<DetectedSignal>  $offshoreSignals
     * @param  list<string>  $agencyTerms  agency-language phrases found (for re-classification)
     */
    public function __construct(
        public string $url,
        public ?string $finalUrl,
        public ?int $status,
        public PageType $pageType,
        public ?string $title = null,
        public ?string $contentHash = null,
        public array $emails = [],
        public array $links = [],
        public array $laravelSignals = [],
        public array $offshoreSignals = [],
        public array $agencyTerms = [],
        public ?string $skippedBecause = null,
    ) {}

    public static function skipped(string $url, PageType $pageType, string $reason, ?int $status = null): self
    {
        return new self($url, null, $status, $pageType, skippedBecause: $reason);
    }

    public function isSuccessful(): bool
    {
        return $this->skippedBecause === null && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
