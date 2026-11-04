<?php

declare(strict_types=1);

use App\Services\Dedupe\CompanyMatcher;

beforeEach(fn () => $this->matcher = new CompanyMatcher);

it('normalises company names for dedupe', function (string $input, string $expected) {
    expect($this->matcher->normalise($input))->toBe($expected);
})->with([
    'ltd' => ['Example Digital Ltd', 'example digital'],
    'limited' => ['Example Digital Limited', 'example digital'],
    'dotted ltd' => ['Example Digital Ltd.', 'example digital'],
    'uk ltd' => ['Example Digital UK Ltd', 'example digital'],
    'llp' => ['Sample Partners LLP', 'sample partners'],
    'plc' => ['Demo Holdings PLC', 'demo holdings'],
    'ampersand' => ['Smith & Example Web Ltd', 'smith and example web'],
    'plus' => ['Code+Pixel Ltd', 'code and pixel'],
    'leading the' => ['The Example Studio Ltd', 'example studio'],
    'punctuation' => ['Example-Digital (Studio) Ltd', 'example digital studio'],
    'accents' => ['Café Créatif Ltd', 'cafe creatif'],
    'spacing' => ['  Example    Digital   ', 'example digital'],
    'suffix only kept' => ['Limited', 'limited'],
    'the only kept' => ['The', 'the'],
    'mid-name ltd kept' => ['Ltd Example Media', 'ltd example media'],
    'empty' => ['  ', ''],
]);

it('scores identical names after normalisation as 1.0', function () {
    expect($this->matcher->similarity('Example Digital Ltd', 'EXAMPLE DIGITAL LIMITED'))->toBe(1.0);
});

it('scores near-identical names above the 0.85 registry threshold', function () {
    expect($this->matcher->similarity('Example Digtal Ltd', 'Example Digital Ltd'))->toBeGreaterThanOrEqual(0.85);
});

it('scores different companies below the threshold', function () {
    expect($this->matcher->similarity('Example Digital Ltd', 'Sample Software Studio Ltd'))->toBeLessThan(0.85)
        ->and($this->matcher->similarity('', 'Example'))->toBe(0.0);
});

it('extracts the bare domain from a url', function (?string $input, ?string $expected) {
    expect($this->matcher->domainOf($input))->toBe($expected);
})->with([
    ['https://www.example-digital.test/careers?x=1', 'example-digital.test'],
    ['http://Example.CO.UK', 'example.co.uk'],
    ['example.co.uk/about', 'example.co.uk'],
    ['www.example.test.', 'example.test'],
    ['https://sub.example.test:8443/x', 'sub.example.test'],
    ['mailto:someone', null],
    ['not a url', null],
    ['', null],
    [null, null],
]);
