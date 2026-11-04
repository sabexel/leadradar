<?php

declare(strict_types=1);

use App\Enums\ExternalService;
use App\Models\ApiUsage;
use App\Services\CompaniesHouse\CompaniesHouseService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.companies_house.key' => 'test-ch-key']);
});

it('searches by name with basic auth and maps results', function () {
    Http::fake(['api.company-information.service.gov.uk/search/companies*' => Http::response(jsonFixture('companies-house/search-exact.json'))]);

    $results = app(CompaniesHouseService::class)->searchCompanies('Example Digital Ltd');

    Http::assertSent(fn (Request $request) => $request['q'] === 'Example Digital Ltd'
        && (int) $request['items_per_page'] === 10
        && $request->header('Authorization')[0] === 'Basic '.base64_encode('test-ch-key:'));

    $first = $results->first();

    expect($results)->toHaveCount(3)
        ->and($first->number)->toBe('01234567')
        ->and($first->title)->toBe('EXAMPLE DIGITAL LTD')
        ->and($first->isActive())->toBeTrue()
        ->and($first->type)->toBe('ltd')
        ->and($first->locality)->toBe('Manchester')
        ->and($first->postcode)->toBe('M1 1AA')
        ->and($first->dateOfCreation?->toDateString())->toBe('2015-02-10')
        ->and($results->last()->isActive())->toBeFalse();
});

it('maps a company profile', function () {
    Http::fake(['api.company-information.service.gov.uk/company/01234567' => Http::response(jsonFixture('companies-house/profile.json'))]);

    $profile = app(CompaniesHouseService::class)->getProfile('01234567');

    expect($profile->number)->toBe('01234567')
        ->and($profile->name)->toBe('EXAMPLE DIGITAL LTD')
        ->and($profile->status)->toBe('active')
        ->and($profile->type)->toBe('ltd')
        ->and($profile->sicCodes)->toBe(['62012', '73110'])
        ->and($profile->lastAccountsType)->toBe('micro-entity')
        ->and($profile->locality())->toBe('Manchester')
        ->and($profile->registeredAddress)->toBe([
            'address_line_1' => '1 Example Street',
            'locality' => 'Manchester',
            'postal_code' => 'M1 1AA',
            'country' => 'England',
        ])
        ->and($profile->dateOfCreation?->toDateString())->toBe('2015-02-10')
        ->and($profile->hasBeenLiquidated)->toBeFalse();
});

it('returns null for unknown company numbers', function () {
    Http::fake(['*' => Http::response(['errors' => [['error' => 'company-profile-not-found']]], 404)]);

    $ch = app(CompaniesHouseService::class);

    expect($ch->getProfile('99999999'))->toBeNull()
        ->and($ch->getOfficers('99999999'))->toBeNull()
        ->and(ApiUsage::where('status_code', 404)->count())->toBe(2);
});

it('maps officers and parses "SURNAME, Firstname Middle" names', function () {
    Http::fake(['api.company-information.service.gov.uk/company/01234567/officers*' => Http::response(jsonFixture('companies-house/officers.json'))]);

    $officers = app(CompaniesHouseService::class)->getOfficers('01234567');

    Http::assertSent(fn (Request $request) => (int) $request['items_per_page'] === 100);

    $jane = $officers->firstWhere('lastName', 'Example');

    expect($officers)->toHaveCount(6)
        ->and($jane->firstName)->toBe('Jane')
        ->and($jane->role)->toBe('director')
        ->and($jane->appointedOn?->toDateString())->toBe('2015-03-01')
        ->and($jane->isActive())->toBeTrue()
        ->and($jane->isFounderRole())->toBeTrue()
        ->and($officers->firstWhere('lastName', 'Demo')->isActive())->toBeFalse()
        ->and($officers->firstWhere('firstName', 'Chris')->lastName)->toBe("O'Example-Smith")
        ->and($officers->firstWhere('lastName', 'Test')->isFounderRole())->toBeFalse()
        ->and(get_object_vars($jane))->not->toHaveKeys(['dateOfBirth', 'nationality', 'address']);
});

it('parses officer names', function (string $raw, string $first, string $last) {
    expect(app(CompaniesHouseService::class)->parseName($raw))->toBe([$first, $last]);
})->with([
    ['EXAMPLE, Jane Mary', 'Jane', 'Example'],
    ['MCEXAMPLE, John', 'John', 'Mcexample'],
    ["O'EXAMPLE-SMITH, Chris", 'Chris', "O'Example-Smith"],
    ['SAMPLE, Pat, Dr', 'Pat', 'Sample'],
    ['EXAMPLE SECRETARIES LIMITED', '', 'Example Secretaries Limited'],
]);

it('logs usage against companies_house', function () {
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-none.json'))]);

    app(CompaniesHouseService::class)->searchCompanies('Example');

    expect(ApiUsage::sole()->service)->toBe(ExternalService::CompaniesHouse)
        ->and(ApiUsage::sole()->endpoint)->toBe('/search/companies');
});
