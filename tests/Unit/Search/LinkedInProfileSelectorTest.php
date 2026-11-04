<?php

declare(strict_types=1);

use App\DTOs\Search\SearchResult;
use App\Services\Search\LinkedInProfileSelector;

beforeEach(fn () => $this->selector = new LinkedInProfileSelector);

function result(string $url, string $title): SearchResult
{
    return new SearchResult($title, $url, null, parse_url($url, PHP_URL_HOST) ?: null);
}

it('accepts only profile URLs, normalised to www.linkedin.com/in/{slug}', function (string $url, ?string $canonical) {
    expect($this->selector->profileUrl($url))->toBe($canonical);
})->with([
    ['https://uk.linkedin.com/in/jane-example-4b2a1?trk=public', 'https://www.linkedin.com/in/jane-example-4b2a1'],
    ['https://www.linkedin.com/in/Jane-Example/', 'https://www.linkedin.com/in/jane-example'],
    ['https://linkedin.com/in/jane-example', 'https://www.linkedin.com/in/jane-example'],
    ['https://uk.linkedin.com/company/example-digital', null],
    ['https://www.linkedin.com/posts/jane-example_hiring-123', null],
    ['https://www.linkedin.com/in/jane-example/details/experience', null],
    ['https://www.linkedin.com/pub/dir/jane/example', null],
    ['https://linkedin.com.evil.test/in/jane-example', null],
    ['https://notlinkedin.com/in/jane-example', null],
]);

it('picks the first profile whose title has both the first and last name', function () {
    $pick = $this->selector->select('Jane', 'Example', collect([
        result('https://uk.linkedin.com/company/example-digital', 'Example Digital | LinkedIn'),
        result('https://uk.linkedin.com/in/jane-smith-77', 'Jane Smith - Example Digital | LinkedIn'),
        result('https://uk.linkedin.com/in/jane-example-4b2a1', 'Jane Example – Founder | LinkedIn'),
    ]));

    expect($pick?->url)->toBe('https://uk.linkedin.com/in/jane-example-4b2a1');
});

it('rejects a profile whose title lacks the first or the last name', function (string $title) {
    expect($this->selector->select('Jane', 'Example', collect([result('https://www.linkedin.com/in/x', $title)])))->toBeNull();
})->with([
    'Jane Smith | LinkedIn',
    'J. Example | LinkedIn',
    'Janet Example | LinkedIn',   // "Jane" must be a whole word
    'Jane Examples | LinkedIn',
]);

it('matches names with accents, apostrophes, hyphens and middle names', function () {
    expect($this->selector->select('José', "O'Example-Smith", collect([
        result('https://www.linkedin.com/in/jose-oexample-smith', 'José Maria O’Example-Smith – Director'),
        result('https://www.linkedin.com/in/jose-o-example-smith', "José Maria O'Example-Smith – Director"),
    ]))?->url)->toBe('https://www.linkedin.com/in/jose-o-example-smith');
});
