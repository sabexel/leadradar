<?php

declare(strict_types=1);

use App\DTOs\Classification\DetectedSignal;
use App\Enums\SignalWeight;
use App\Services\Crawler\OffshoreSignalDetector;

beforeEach(fn () => $this->detector = new OffshoreSignalDetector);

function textFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/classification/{$name}");
}

/**
 * @param  list<DetectedSignal>  $signals
 * @return array<string, string>
 */
function byType(array $signals): array
{
    return collect($signals)->mapWithKeys(fn (DetectedSignal $s) => [$s->type => $s->weight->value])->all();
}

it('flags an offshore delivery centre with an offshore country as medium', function () {
    $signals = $this->detector->detectInText(textFixture('offshore-site.txt'), 'https://sample-code-labs.test/about');

    expect(byType($signals))->toBe(['offshore_office' => 'medium'])
        ->and($signals[0]->url)->toBe('https://sample-code-labs.test/about')
        ->and($signals[0]->detail)->toBe('"offshore" with "india"');
});

it('flags hire-a-developer hourly pricing as strong', function (string $text) {
    expect(byType($this->detector->detectInText($text)))->toHaveKey('hourly_rate_pricing', 'strong');
})->with([
    'fixture' => fn () => textFixture('hourly-pricing.txt'),
    'pounds per hour' => 'Dedicated developers at £12 per hour.',
    'euros per hr' => 'Hire Laravel developers starting €18/hr',
    'decimals' => 'Hire a PHP developer from $14.50/hour',
]);

it('treats outsourcing language without an offshore country as weak', function () {
    expect(byType($this->detector->detectInText(textFixture('weak-outsourcing.txt'))))->toBe(['outsourcing_language' => 'weak']);
});

it('finds nothing in a normal UK agency ad', function () {
    expect($this->detector->detectInText(textFixture('agency-ad.txt')))->toBe([])
        ->and($this->detector->detectInText(''))->toBe([]);
});

it('does not treat a salary as hourly offshore pricing', function () {
    expect(byType($this->detector->detectInText('Laravel developer, £45,000 per annum, hybrid in Leeds.')))->toBe([]);
});

it('flags officers who all live outside the UK', function () {
    expect($this->detector->detectOfficersAbroad(['India', 'india', null])?->weight)->toBe(SignalWeight::Medium)
        ->and($this->detector->detectOfficersAbroad(['India', 'England']))->toBeNull()
        ->and($this->detector->detectOfficersAbroad(['Scotland']))->toBeNull()
        ->and($this->detector->detectOfficersAbroad([null, '']))->toBeNull()
        ->and($this->detector->detectOfficersAbroad([]))->toBeNull();
});

it('upgrades officers abroad to strong only when combined with offshore text', function () {
    $abroad = $this->detector->detectOfficersAbroad(['Pakistan']);
    $text = $this->detector->detectInText(textFixture('weak-outsourcing.txt'));

    expect(byType($this->detector->combine([], $abroad)))->toBe(['officers_abroad' => 'medium'])
        ->and(collect($this->detector->combine($text, $abroad))->last()->weight)->toBe(SignalWeight::Strong)
        ->and($this->detector->combine($text, null))->toBe($text);
});
