<?php

declare(strict_types=1);

use App\DTOs\Search\SearchResult;
use App\Models\Setting;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Search\AgencyResultSelector;
use App\Services\Search\AgencySearchPlanner;
use Illuminate\Support\Collection;

function braveResults(string $fixture): Collection
{
    $matcher = app(CompanyMatcher::class);

    return collect(jsonFixture("brave/{$fixture}.json")['web']['results'])
        ->map(fn (array $r): SearchResult => new SearchResult($r['title'], $r['url'], $r['description'], $matcher->domainOf($r['url'])));
}

it('keeps only agency home pages from an agency search', function () {
    $picked = app(AgencyResultSelector::class)->select(braveResults('agency-search'), 'CA');

    // A home page with a tracking query still counts (Lakeside Digital).
    expect(array_column($picked, 'domain'))->toBe(['maplepixel.example.ca', 'northerncommerce.example.com', 'lakesidedigital.example.com'])
        ->and($picked[2]['name'])->toBe('Lakeside Digital')
        ->and($picked[0]['name'])->toBe('Maple Pixel Studio')
        ->and($picked[0]['url'])->toBe('https://maplepixel.example.ca')
        ->and($picked[1]['name'])->toBe('Northern Commerce');
});

it('drops another market\'s country domain but keeps generic ones', function () {
    $picked = app(AgencyResultSelector::class)->select(braveResults('agency-search'), 'GB');

    // .ca is foreign for a UK search; .in always is; .com stays.
    expect(array_column($picked, 'domain'))->toBe(['northerncommerce.example.com', 'lakesidedigital.example.com']);
});

it('interleaves markets and moves the cursor and result page on', function () {
    config(['leadradar.agency_markets' => [
        'GB' => ['cities' => ['Leeds', 'York']],
        'NL' => ['cities' => ['Utrecht'], 'phrases' => ['webbureau']],
    ], 'leadradar.agency_phrases' => ['wordpress agency']]);
    Setting::put('source.agency.countries', ['GB', 'NL', 'XX']);
    Setting::put('source.agency.queries_per_day', 3);
    Setting::put('source.agency.cursor', 0);

    $planner = app(AgencySearchPlanner::class);

    expect($planner->combinations())->toBe([
        ['phrase' => 'wordpress agency', 'city' => 'Leeds', 'country' => 'GB'],
        ['phrase' => 'webbureau', 'city' => 'Utrecht', 'country' => 'NL'],
        ['phrase' => 'wordpress agency', 'city' => 'York', 'country' => 'GB'],
        ['phrase' => 'wordpress agency', 'city' => 'Utrecht', 'country' => 'NL'],
    ]);

    $first = $planner->nextBatch();
    $second = $planner->nextBatch();

    expect(array_column($first, 'city'))->toBe(['Leeds', 'Utrecht', 'York'])
        ->and(array_column($first, 'offset'))->toBe([0, 0, 0])
        // Wraps round the 4 combinations; the second pass asks for the next result page.
        ->and(array_column($second, 'city'))->toBe(['Utrecht', 'Leeds', 'Utrecht'])
        ->and(array_column($second, 'offset'))->toBe([0, 1, 1])
        ->and(Setting::get('source.agency.cursor'))->toBe(6);
});

it('plans nothing when the source is off or no market is enabled', function () {
    Setting::put('source.agency.countries', []);

    expect(app(AgencySearchPlanner::class)->nextBatch())->toBe([]);
});
