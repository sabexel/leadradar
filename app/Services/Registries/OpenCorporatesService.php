<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\DTOs\CompaniesHouse\CompanyProfile;
use App\DTOs\CompaniesHouse\CompanySearchResult;
use App\DTOs\CompaniesHouse\Officer;
use App\DTOs\Registries\RegistryCompanyDetails;
use App\Enums\ExternalService;
use App\Services\Support\ExternalApiService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenCorporates API v0.4 (https://api.opencorporates.com/documentation/API-Reference), used for US state
 * registries (P4.2, D110). Auth: `api_token` query parameter. Every call counts against the plan's budget.
 * Registration numbers are stored as "{jurisdiction}/{number}", e.g. "us_de/1234567".
 */
final class OpenCorporatesService extends ExternalApiService
{
    private const FOUNDER_POSITIONS = '/\b(?:director|president|ceo|chief executive|founder|co-founder|owner|managing member|member|manager|principal|chair(?:man|person)?)\b/';

    private const NOT_FOUNDER_POSITIONS = '/\b(?:agent|secretary|treasurer|incorporator|organi[sz]er|vice|assistant|clerk)\b/';

    private const CORPORATE_NAME = '/\b(?:llc|inc|corp|corporation|company|ltd|lp|llp|services|system|trust|holdings)\b\.?$/';

    protected function service(): ExternalService
    {
        return ExternalService::OpenCorporates;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.opencorporates.base_url'))
            ->withQueryParameters(['api_token' => $this->requireConfig('token')])
            ->acceptJson()
            ->timeout((int) config('services.opencorporates.timeout', 20));
    }

    /**
     * Active, domestic (non-branch) US companies, best match first.
     *
     * @return Collection<int, CompanySearchResult>
     */
    public function searchCompanies(string $name, int $limit = 10): Collection
    {
        $data = $this->json($this->get('/companies/search', [
            'q' => $name,
            'country_code' => 'us',
            'inactive' => 'false',
            'branch' => 'false',
            'order' => 'score',
            'per_page' => min(100, max(1, $limit)),
        ], '/companies/search'), '/companies/search');

        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        return collect(is_array($results['companies'] ?? null) ? $results['companies'] : [])
            ->map(fn (mixed $row): mixed => is_array($row) ? ($row['company'] ?? null) : null)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?CompanySearchResult {
                $number = $this->number($item);
                $name = $this->text($item['name'] ?? null);

                if ($number === null || $name === null || ($item['branch'] ?? null) === 'F') {
                    return null;
                }

                $address = $this->address($item['registered_address'] ?? null);

                return new CompanySearchResult(
                    number: $number,
                    title: $name,
                    status: $this->status($item),
                    type: $this->companyType($this->text($item['company_type'] ?? null)),
                    addressSnippet: $this->text($item['registered_address_in_full'] ?? null),
                    locality: $address['locality'] ?? null,
                    postcode: $address['postal_code'] ?? null,
                    dateOfCreation: $this->date($item['incorporation_date'] ?? null),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * Profile and officers in one call. Null when OpenCorporates has no such company (404).
     */
    public function getCompany(string $number): ?RegistryCompanyDetails
    {
        if (preg_match('#^(us_[a-z]{2})/(.+)$#', $number, $m) !== 1) {
            return null;
        }

        $response = $this->get('/companies/'.$m[1].'/'.rawurlencode($m[2]), [], '/companies/{jurisdiction}/{number}', allowNotFound: true);

        if ($response->notFound()) {
            return null;
        }

        $data = $this->json($response, '/companies/{jurisdiction}/{number}');
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        $company = is_array($results['company'] ?? null) ? $results['company'] : [];

        $profile = new CompanyProfile(
            number: $this->number($company) ?? $number,
            name: $this->text($company['name'] ?? null) ?? '',
            status: $this->status($company),
            type: $this->companyType($this->text($company['company_type'] ?? null)),
            sicCodes: $this->industryCodes($company['industry_codes'] ?? null),
            registeredAddress: $this->address($company['registered_address'] ?? null),
            lastAccountsType: null,
            dateOfCreation: $this->date($company['incorporation_date'] ?? null),
            hasBeenLiquidated: false,
            hasInsolvencyHistory: false,
        );

        return new RegistryCompanyDetails($profile, $this->officers($company['officers'] ?? null));
    }

    /**
     * Free-text US entity types → `llc`, `corporation`, `partnership`, `nonprofit`, `other`, or `unknown` when blank.
     */
    public function companyType(?string $type): string
    {
        if ($type === null) {
            return 'unknown';
        }

        $type = Str::lower($type);

        return match (true) {
            preg_match('/non-?profit|not[- ]for[- ]profit|non profit|charit/', $type) === 1 => 'nonprofit',
            preg_match('/partnership|\b(?:lp|llp|lllp)\b/', $type) === 1 => 'partnership',
            preg_match('/\bllc\b|\bl\.l\.c\.|limited liability company/', $type) === 1 => 'llc',
            preg_match('/corporation|\bcorp\b|\binc\b|business|stock|professional|for-profit|\bpc\b/', $type) === 1 => 'corporation',
            default => 'other',
        };
    }

    /**
     * "PAUL MILTON ANDERSON" → ["Paul", "Anderson"]; "ANDERSON, PAUL" → ["Paul", "Anderson"].
     * Company-like names (registered agents, corporate members) give ["", ""], so they are skipped.
     *
     * @return array{0: string, 1: string}
     */
    public function parseName(string $name): array
    {
        $name = Str::squish($name);

        if (preg_match(self::CORPORATE_NAME, Str::lower($name)) === 1) {
            return ['', ''];
        }

        if (str_contains($name, ',')) {
            [$last, $rest] = array_map('trim', explode(',', $name, 2));
            $parts = [Str::of($rest)->explode(' ')->first() ?? '', $last];
        } else {
            $words = explode(' ', $name);
            $parts = count($words) < 2 ? ['', ''] : [$words[0], (string) end($words)];
        }

        return [$this->titleCase((string) $parts[0]), $this->titleCase((string) $parts[1])];
    }

    /**
     * @return Collection<int, Officer>
     */
    private function officers(mixed $officers): Collection
    {
        return collect(is_array($officers) ? $officers : [])
            ->map(fn (mixed $row): mixed => is_array($row) ? ($row['officer'] ?? null) : null)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?Officer {
                $name = $this->text($item['name'] ?? null);
                $position = Str::lower($this->text($item['position'] ?? null) ?? '');

                if ($name === null || preg_match(self::NOT_FOUNDER_POSITIONS, $position) === 1 || preg_match(self::FOUNDER_POSITIONS, $position) !== 1) {
                    return null;
                }

                [$first, $last] = $this->parseName($name);

                return new Officer(
                    firstName: $first,
                    lastName: $last,
                    role: str_contains($position, 'director') ? 'director' : 'founder',
                    appointedOn: $this->date($item['start_date'] ?? null),
                    resignedOn: $this->date($item['end_date'] ?? null),
                    countryOfResidence: null,
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<mixed>  $company
     */
    private function number(array $company): ?string
    {
        $jurisdiction = $this->text($company['jurisdiction_code'] ?? null);
        $number = $this->text($company['company_number'] ?? null);

        return $jurisdiction === null || $number === null ? null : Str::lower($jurisdiction).'/'.$number;
    }

    /**
     * OpenCorporates' `inactive` flag, else the dissolution date; registries without a status give `unknown`.
     *
     * @param  array<mixed>  $company
     */
    private function status(array $company): string
    {
        return match (true) {
            ($company['inactive'] ?? null) === true, $this->text($company['dissolution_date'] ?? null) !== null => 'inactive',
            ($company['inactive'] ?? null) === false => 'active',
            default => 'unknown',
        };
    }

    /**
     * @return list<string>
     */
    private function industryCodes(mixed $codes): array
    {
        return array_values(collect(is_array($codes) ? $codes : [])
            ->map(fn (mixed $row): mixed => is_array($row) && is_array($row['industry_code'] ?? null) ? ($row['industry_code']['code'] ?? null) : null)
            ->filter(fn (mixed $code): bool => is_string($code) && $code !== '')
            ->map(fn (mixed $code): string => (string) $code)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return array<string, string>
     */
    private function address(mixed $address): array
    {
        if (! is_array($address)) {
            return [];
        }

        return collect([
            'address_line_1' => $this->text($address['street_address'] ?? null),
            'locality' => $this->text($address['locality'] ?? null),
            'region' => $this->text($address['region'] ?? null),
            'postal_code' => $this->text($address['postal_code'] ?? null),
            'country' => $this->text($address['country'] ?? null),
        ])->filter()->all();
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
            return CarbonImmutable::parse($value, 'America/New_York')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
