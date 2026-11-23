<?php

declare(strict_types=1);

namespace App\Services\Places;

use App\DTOs\Places\PlaceLead;
use App\DTOs\Places\PlaceSearchPage;
use App\Enums\ExternalService;
use App\Services\Support\ExternalApiService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Places API (New) — official Text Search only (D111). Never scrapes Maps HTML (docs/09).
 */
final class GooglePlacesService extends ExternalApiService
{
    private const FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount,places.types,places.googleMapsUri,nextPageToken';

    protected function service(): ExternalService
    {
        return ExternalService::GooglePlaces;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->requireConfig('base_url'), '/'))
            ->withHeaders([
                'X-Goog-Api-Key' => $this->requireConfig('key'),
                'X-Goog-FieldMask' => self::FIELD_MASK,
                'Content-Type' => 'application/json',
            ])
            ->timeout((int) config('services.google_places.timeout', 20))
            ->acceptJson();
    }

    /**
     * One Text Search page for a business type in a city. Callers filter "no website + min reviews".
     */
    public function searchText(string $city, string $includedType, ?string $pageToken = null): PlaceSearchPage
    {
        $body = array_filter([
            'textQuery' => trim("{$includedType} in {$city}, UK"),
            'includedType' => $includedType,
            'strictTypeFiltering' => true,
            'regionCode' => 'GB',
            'languageCode' => 'en',
            'pageSize' => 20,
            'pageToken' => $pageToken,
        ], fn (mixed $v): bool => $v !== null && $v !== '');

        $data = $this->json($this->post('/v1/places:searchText', $body, 'places:searchText'), 'places:searchText');

        $places = collect(is_array($data['places'] ?? null) ? $data['places'] : [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): ?PlaceLead => $this->mapPlace($item))
            ->filter()
            ->values();

        $token = is_string($data['nextPageToken'] ?? null) && $data['nextPageToken'] !== '' ? $data['nextPageToken'] : null;

        return new PlaceSearchPage($places, $token);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapPlace(array $item): ?PlaceLead
    {
        $id = is_string($item['id'] ?? null) ? Str::after($item['id'], 'places/') : null;
        $id = ($id === null || $id === '') && is_string($item['id'] ?? null) ? $item['id'] : $id;
        $name = is_array($item['displayName'] ?? null) && is_string($item['displayName']['text'] ?? null)
            ? Str::squish($item['displayName']['text'])
            : null;

        if ($id === null || $id === '' || $name === null || $name === '') {
            return null;
        }

        // Already has a site — not a "needs a website" lead.
        if (filled($item['websiteUri'] ?? null)) {
            return null;
        }

        $address = is_string($item['formattedAddress'] ?? null) ? Str::squish($item['formattedAddress']) : null;
        $phone = is_string($item['nationalPhoneNumber'] ?? null) && $item['nationalPhoneNumber'] !== ''
            ? $item['nationalPhoneNumber']
            : (is_string($item['internationalPhoneNumber'] ?? null) ? $item['internationalPhoneNumber'] : null);

        $types = array_values(array_filter(
            is_array($item['types'] ?? null) ? $item['types'] : [],
            fn (mixed $t): bool => is_string($t) && $t !== '',
        ));

        return new PlaceLead(
            placeId: $id,
            name: $name,
            address: $address,
            locality: $this->locality($address),
            phone: $phone,
            rating: is_numeric($item['rating'] ?? null) ? (float) $item['rating'] : null,
            reviewCount: is_numeric($item['userRatingCount'] ?? null) ? (int) $item['userRatingCount'] : 0,
            types: $types,
            mapsUrl: is_string($item['googleMapsUri'] ?? null) ? $item['googleMapsUri'] : null,
        );
    }

    private function locality(?string $address): ?string
    {
        if ($address === null) {
            return null;
        }

        // "12 High St, Manchester M1 1AA, UK" → Manchester
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));

        foreach ($parts as $part) {
            if (preg_match('/^[A-Z]{1,2}\d/i', $part) === 1) {
                continue; // postcode-ish
            }

            if (preg_match('/^(?:UK|United Kingdom|England|Scotland|Wales)$/i', $part) === 1) {
                continue;
            }

            if (preg_match('/\d/', $part) === 1 && str_contains($part, ' ')) {
                continue; // street line
            }

            return Str::title(mb_strtolower($part));
        }

        return null;
    }
}
