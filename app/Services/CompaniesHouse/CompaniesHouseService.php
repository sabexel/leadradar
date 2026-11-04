<?php

declare(strict_types=1);

namespace App\Services\CompaniesHouse;

use App\DTOs\CompaniesHouse\CompanyProfile;
use App\DTOs\CompaniesHouse\CompanySearchResult;
use App\DTOs\CompaniesHouse\Officer;
use App\Enums\ExternalService;
use App\Services\Support\ExternalApiService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Companies House public data API (https://developer-specs.company-information.service.gov.uk).
 * Auth: HTTP Basic, API key as username, empty password. Limit: 600 requests / 5 min per key.
 */
final class CompaniesHouseService extends ExternalApiService
{
    protected function service(): ExternalService
    {
        return ExternalService::CompaniesHouse;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.companies_house.base_url'))
            ->withBasicAuth($this->requireConfig('key'), '')
            ->acceptJson()
            ->timeout((int) config('services.companies_house.timeout', 15));
    }

    /**
     * @return Collection<int, CompanySearchResult>
     */
    public function searchCompanies(string $name, int $limit = 10): Collection
    {
        $data = $this->json($this->get('/search/companies', [
            'q' => $name,
            'items_per_page' => min(100, max(1, $limit)),
        ], '/search/companies'), '/search/companies');

        return collect(is_array($data['items'] ?? null) ? $data['items'] : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?CompanySearchResult {
                $number = $this->text($item['company_number'] ?? null);
                $title = $this->text($item['title'] ?? null);

                if ($number === null || $title === null) {
                    return null;
                }

                $address = is_array($item['address'] ?? null) ? $item['address'] : [];

                return new CompanySearchResult(
                    number: $number,
                    title: $title,
                    status: $this->text($item['company_status'] ?? null),
                    type: $this->text($item['company_type'] ?? null),
                    addressSnippet: $this->text($item['address_snippet'] ?? null),
                    locality: $this->text($address['locality'] ?? null),
                    postcode: $this->text($address['postal_code'] ?? null),
                    dateOfCreation: $this->date($item['date_of_creation'] ?? null),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * Null when Companies House has no such company (404).
     */
    public function getProfile(string $number): ?CompanyProfile
    {
        $response = $this->get('/company/'.rawurlencode($number), [], '/company/{number}', allowNotFound: true);

        if ($response->notFound()) {
            return null;
        }

        $data = $this->json($response, '/company/{number}');

        $address = collect(is_array($data['registered_office_address'] ?? null) ? $data['registered_office_address'] : [])
            ->only(['premises', 'address_line_1', 'address_line_2', 'locality', 'region', 'postal_code', 'country'])
            ->map(fn (mixed $value): ?string => $this->text($value))
            ->filter()
            ->all();

        $accounts = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];
        $lastAccounts = is_array($accounts['last_accounts'] ?? null) ? $accounts['last_accounts'] : [];

        return new CompanyProfile(
            number: $this->text($data['company_number'] ?? null) ?? $number,
            name: $this->text($data['company_name'] ?? null) ?? '',
            status: $this->text($data['company_status'] ?? null),
            type: $this->text($data['type'] ?? null),
            sicCodes: array_values(array_filter(
                is_array($data['sic_codes'] ?? null) ? $data['sic_codes'] : [],
                fn (mixed $code): bool => is_string($code) && $code !== '',
            )),
            registeredAddress: $address,
            lastAccountsType: $this->text($lastAccounts['type'] ?? null),
            dateOfCreation: $this->date($data['date_of_creation'] ?? null),
            hasBeenLiquidated: ($data['has_been_liquidated'] ?? false) === true,
            hasInsolvencyHistory: ($data['has_insolvency_history'] ?? false) === true,
        );
    }

    /**
     * Every officer, current and resigned (null when the company is unknown).
     *
     * @return Collection<int, Officer>|null
     */
    public function getOfficers(string $number): ?Collection
    {
        $response = $this->get('/company/'.rawurlencode($number).'/officers', ['items_per_page' => 100], '/company/{number}/officers', allowNotFound: true);

        if ($response->notFound()) {
            return null;
        }

        $data = $this->json($response, '/company/{number}/officers');

        return collect(is_array($data['items'] ?? null) ? $data['items'] : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?Officer {
                $role = $this->text($item['officer_role'] ?? null);
                $name = $this->text($item['name'] ?? null);

                if ($role === null || $name === null) {
                    return null;
                }

                [$first, $last] = $this->parseName($name);

                return new Officer(
                    firstName: $first,
                    lastName: $last,
                    role: $role,
                    appointedOn: $this->date($item['appointed_on'] ?? null),
                    resignedOn: $this->date($item['resigned_on'] ?? null),
                    countryOfResidence: $this->text($item['country_of_residence'] ?? null),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * "SURNAME, Firstname Middle" → ["Firstname", "Surname"]. Names without a comma (corporate
     * officers) go entirely into the last name.
     *
     * @return array{0: string, 1: string}
     */
    public function parseName(string $name): array
    {
        if (! str_contains($name, ',')) {
            return ['', $this->titleCase($name)];
        }

        [$surname, $forenames] = array_map('trim', explode(',', $name, 3) + [1 => '']);
        $first = Str::of($forenames)->squish()->explode(' ')->first() ?? '';

        return [$this->titleCase((string) $first), $this->titleCase($surname)];
    }

    private function titleCase(string $value): string
    {
        return ucwords(mb_strtolower(Str::squish($value)), " -'");
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Europe/London')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
