<?php

declare(strict_types=1);

use App\DTOs\Search\SearchResult;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Search\WebsiteSelector;

beforeEach(fn () => $this->selector = new WebsiteSelector(new CompanyMatcher));

function hit(string $url, string $title, ?string $description = null): SearchResult
{
    return new SearchResult($title, $url, $description, (new CompanyMatcher)->domainOf($url));
}

it('keeps only distinctive name words as tokens', function (string $name, array $tokens) {
    expect($this->selector->significantTokens($name))->toBe($tokens);
})->with([
    ['EXAMPLE DIGITAL LTD', ['example']],
    ['Sample Software Studio Ltd', ['sample']],
    ['Smith & Example Web Ltd', ['smith', 'example']],
    ['Digital Studio Ltd', ['digital', 'studio']], // all generic → keep them all
    ['AB Ltd', []],                               // too short to identify anything
]);

it('blocks directories, socials, job boards and gov.uk, including subdomains', function (string $domain) {
    expect($this->selector->isBlocked($domain))->toBeTrue();
})->with(['suite.endole.co.uk', 'uk.linkedin.com', 'linkedin.com', 'find-and-update.company-information.service.gov.uk', 'reed.co.uk', 'clutch.co', 'example.gov.uk']);

it('does not block ordinary company domains', function () {
    expect($this->selector->isBlocked('example-digital.test'))->toBeFalse()
        ->and($this->selector->isBlocked('notlinkedin.com'))->toBeFalse();
});

it('skips a directory first result and picks the company site', function () {
    $pick = $this->selector->select('EXAMPLE DIGITAL LTD', collect([
        hit('https://suite.endole.co.uk/insight/company/01234567', 'EXAMPLE DIGITAL LTD - Endole'),
        hit('https://www.example-digital.test/', 'Example Digital – Laravel agency'),
    ]));

    expect($pick?->domain)->toBe('example-digital.test')
        ->and($pick?->origin())->toBe('https://www.example-digital.test');
});

it('accepts a match on the domain alone', function () {
    $pick = $this->selector->select('EXAMPLE DIGITAL LTD', collect([hit('https://exampledigital.test/', 'Home')]));

    expect($pick?->domain)->toBe('exampledigital.test');
});

it('never picks a deep page that only mentions the company in its title or snippet', function () {
    // Shapes seen in the first live run: company-data directories that are not on the blocklist.
    expect($this->selector->select('TRYBE LTD', collect([
        hit('https://www.unlisted-bizdirectory.test/company/12345678/trybe-ltd', 'TRYBE LTD - Company profile', 'Trybe Ltd is an active company…'),
        hit('https://intelligence.unlisted-experts.test/expert/trybe-head-of-engineering', 'Head of Engineering at Trybe'),
    ])))->toBeNull();
});

it('prefers a result whose domain carries the name over an earlier home page that only has it in the title', function () {
    $pick = $this->selector->select('TRYBE LTD', collect([
        hit('https://www.other-reviews.test/', 'Trybe reviews and ratings'),
        hit('https://www.trybe.test/', 'Spa booking software'),
    ]));

    expect($pick?->domain)->toBe('trybe.test');
});

it('accepts a home page whose title names the company when the domain does not', function (string $url) {
    $pick = $this->selector->select('NATIONAL RESIDENTIAL LANDLORDS ASSOCIATION', collect([hit($url, 'National Residential Landlords Association')]));

    expect($pick?->domain)->toBe('nrla.test');
})->with(['https://www.nrla.test/', 'https://www.nrla.test', 'https://www.nrla.test/en-gb/', 'https://www.nrla.test/index.html']);

it('blocks the directories found in the first live run', function (string $domain) {
    expect($this->selector->isBlocked($domain))->toBeTrue();
})->with(['gbrbusiness.com', 'opengovuk.com', 'checkall.co.uk', 'ukbizdb.co.uk', 'reportingaccounts.com', 'huntukvisasponsors.com', 'intelligence.arbolus.com', 'freehire.me']);

it('rejects results that never mention the company', function () {
    expect($this->selector->select('EXAMPLE DIGITAL LTD', collect([
        hit('https://www.sample-rankings.test/', 'Top 10 web agencies in Manchester'),
    ])))->toBeNull();
});

it('does not match a token inside a longer word', function () {
    expect($this->selector->select('ACME LTD', collect([hit('https://www.other.test/', 'Acmeplex cinemas')])))->toBeNull();
});
