<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\DTOs\CompaniesHouse\CompanyProfile;
use App\DTOs\CompaniesHouse\CompanySearchResult;
use App\Enums\ExternalService;
use App\Services\Support\ExternalApiService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * KVK Handelsregister APIs (https://developers.kvk.nl), Netherlands (P4.2, D110).
 * Auth: `apikey` header. Zoeken is free (cost 0); each Basisprofiel is billed (€0.02, cost 1).
 * KVK publishes no directors, so there is no officers call.
 */
final class KvkService extends ExternalApiService
{
    /**
     * Employee counts that earn the "small company" points (the KVK stand-in for UK small accounts).
     */
    public const MICRO_MAX_EMPLOYEES = 9;

    public const SMALL_MAX_EMPLOYEES = 49;

    protected function service(): ExternalService
    {
        return ExternalService::Kvk;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.kvk.base_url'))
            ->withHeaders(['apikey' => $this->requireConfig('key')])
            ->acceptJson()
            ->timeout((int) config('services.kvk.timeout', 15));
    }

    /**
     * Main branches (hoofdvestigingen) whose name matches, one per KVK number. A 404 means "no matches".
     *
     * @return Collection<int, CompanySearchResult>
     */
    public function searchCompanies(string $name, int $limit = 10): Collection
    {
        $response = $this->get('/v2/zoeken', [
            'naam' => $name,
            'type' => 'hoofdvestiging',
            'resultatenPerPagina' => min(100, max(1, $limit)),
        ], '/v2/zoeken', cost: 0.0, allowNotFound: true);

        if ($response->notFound()) {
            return collect();
        }

        $data = $this->json($response, '/v2/zoeken');

        return collect(is_array($data['resultaten'] ?? null) ? $data['resultaten'] : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): ?CompanySearchResult {
                $number = $this->text($item['kvkNummer'] ?? null);
                $name = $this->text($item['naam'] ?? null);

                if ($number === null || $name === null) {
                    return null;
                }

                $address = is_array($item['adres'] ?? null) && is_array($item['adres']['binnenlandsAdres'] ?? null)
                    ? $item['adres']['binnenlandsAdres']
                    : [];
                $place = $this->text($address['plaats'] ?? null);

                return new CompanySearchResult(
                    number: $number,
                    title: $name,
                    status: ($item['actief'] ?? 'Ja') === 'Nee' ? 'inactive' : 'active',
                    type: null,
                    addressSnippet: collect([$this->text($address['straatnaam'] ?? null), $place])->filter()->implode(', ') ?: null,
                    locality: $place,
                    postcode: $this->text($address['postcode'] ?? null),
                    dateOfCreation: null,
                );
            })
            ->filter()
            ->unique(fn (CompanySearchResult $r): string => $r->number)
            ->values();
    }

    /**
     * Null when KVK has no such number (404).
     */
    public function getProfile(string $number): ?CompanyProfile
    {
        $response = $this->get('/v1/basisprofielen/'.rawurlencode($number), [], '/v1/basisprofielen/{kvkNummer}', allowNotFound: true);

        if ($response->notFound()) {
            return null;
        }

        $data = $this->json($response, '/v1/basisprofielen/{kvkNummer}');
        $embedded = is_array($data['_embedded'] ?? null) ? $data['_embedded'] : [];
        $owner = is_array($embedded['eigenaar'] ?? null) ? $embedded['eigenaar'] : [];
        $main = is_array($embedded['hoofdvestiging'] ?? null) ? $embedded['hoofdvestiging'] : [];
        $registration = is_array($data['materieleRegistratie'] ?? null) ? $data['materieleRegistratie'] : [];

        $employees = $data['totaalWerkzamePersonen'] ?? $main['totaalWerkzamePersonen'] ?? null;
        $type = $this->legalForm($this->text($owner['rechtsvorm'] ?? null));

        return new CompanyProfile(
            number: $this->text($data['kvkNummer'] ?? null) ?? $number,
            name: $this->text($data['statutaireNaam'] ?? null) ?? $this->text($data['naam'] ?? null) ?? '',
            status: $this->text($registration['datumEinde'] ?? null) === null ? 'active' : 'inactive',
            type: $type,
            sicCodes: $this->sbiCodes($data['sbiActiviteiten'] ?? null),
            // A sole trader's or partnership's address is usually a home address — personal data (docs/09).
            registeredAddress: in_array($type, ['bv', 'nv'], true) ? $this->address($main['adressen'] ?? null) : [],
            lastAccountsType: match (true) {
                ! is_int($employees) => null,
                $employees <= self::MICRO_MAX_EMPLOYEES => 'micro-entity',
                $employees <= self::SMALL_MAX_EMPLOYEES => 'small',
                default => null,
            },
            dateOfCreation: $this->date($registration['datumAanvang'] ?? null) ?? $this->date($data['formeleRegistratiedatum'] ?? null),
            hasBeenLiquidated: false,
            hasInsolvencyHistory: false,
            noMailing: ($data['indNonMailing'] ?? null) === 'Ja',
        );
    }

    /**
     * "Besloten vennootschap" → `bv`, "Eenmanszaak" → `eenmanszaak`, etc. Unknown forms become a slug.
     */
    public function legalForm(?string $form): ?string
    {
        if ($form === null) {
            return null;
        }

        $form = Str::lower(Str::ascii($form));

        return match (true) {
            str_contains($form, 'besloten vennootschap') => 'bv',
            str_contains($form, 'naamloze vennootschap') => 'nv',
            str_contains($form, 'eenmanszaak') => 'eenmanszaak',
            str_contains($form, 'vennootschap onder firma') => 'vof',
            str_contains($form, 'commanditaire vennootschap') => 'cv',
            default => Str::slug($form),
        };
    }

    /**
     * Main activity first.
     *
     * @return list<string>
     */
    private function sbiCodes(mixed $activities): array
    {
        return array_values(collect(is_array($activities) ? $activities : [])
            ->filter(fn (mixed $a): bool => is_array($a) && is_string($a['sbiCode'] ?? null) && $a['sbiCode'] !== '')
            ->sortBy(fn (array $a): int => ($a['indHoofdactiviteit'] ?? null) === 'Ja' ? 0 : 1)
            ->map(fn (array $a): string => (string) $a['sbiCode'])
            ->unique()
            ->values()
            ->all());
    }

    /**
     * Visiting address (bezoekadres) of the main branch, else its first address. Company address only.
     *
     * @return array<string, string>
     */
    private function address(mixed $addresses): array
    {
        $all = collect(is_array($addresses) ? $addresses : [])->filter(fn (mixed $a): bool => is_array($a));
        $address = $all->first(fn (array $a): bool => ($a['type'] ?? null) === 'bezoekadres') ?? $all->first() ?? [];

        if (($address['indAfgeschermd'] ?? null) === 'Ja') {
            return [];
        }

        $street = collect([
            $this->text($address['straatnaam'] ?? null),
            $this->text($address['huisnummer'] ?? null),
            $this->text($address['huisnummerToevoeging'] ?? null),
        ])->filter()->implode(' ');

        return collect([
            'address_line_1' => $street,
            'locality' => $this->text($address['plaats'] ?? null),
            'postal_code' => $this->text($address['postcode'] ?? null),
            'country' => $this->text($address['land'] ?? null),
        ])->filter()->all();
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * KVK dates are `YYYYMMDD`; unknown parts are `00`.
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) !== 1 || $m[1] === '0000') {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], max(1, (int) $m[2]), max(1, (int) $m[3]), 0, 0, 0, 'Europe/Amsterdam');
    }
}
