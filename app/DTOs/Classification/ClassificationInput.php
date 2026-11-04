<?php

declare(strict_types=1);

namespace App\DTOs\Classification;

/**
 * Everything the classifier looks at, as plain values.
 */
final readonly class ClassificationInput
{
    /**
     * @param  list<string>  $sicCodes
     * @param  list<string>  $adTexts  job-ad titles + descriptions (what the company says when hiring)
     * @param  list<string|null>  $officerCountries  country_of_residence of active founders
     * @param  list<string>  $siteTexts  website text, or the agency terms stored per crawled page
     * @param  list<DetectedSignal>  $siteOffshoreSignals  offshore signals already detected on crawled pages
     */
    public function __construct(
        public string $name,
        public array $sicCodes = [],
        public array $adTexts = [],
        public array $officerCountries = [],
        public array $siteTexts = [],
        public array $siteOffshoreSignals = [],
    ) {}

    public function allText(): string
    {
        return implode("\n", [$this->name, ...$this->adTexts, ...$this->siteTexts]);
    }
}
