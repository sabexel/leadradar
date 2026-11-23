<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePipelineSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Trim, drop blanks and duplicates; keywords lower-case, countries upper-case.
     */
    protected function prepareForValidation(): void
    {
        $clean = function (mixed $list): array {
            $values = [];

            foreach (is_array($list) ? $list : [] as $value) {
                $value = is_string($value) ? trim($value) : '';

                if ($value !== '') {
                    $values[] = $value;
                }
            }

            return array_values(array_unique($values));
        };

        $registry = $this->input('registry_search');
        $registry = is_array($registry) ? $registry : [];
        $agency = $this->input('agency');
        $agency = is_array($agency) ? $agency : [];
        $places = $this->input('website_places');
        $places = is_array($places) ? $places : [];
        $allowedTypes = array_keys(config('leadradar.place_types', []));

        $this->merge([
            'keywords' => array_values(array_unique(array_map('mb_strtolower', $clean($this->input('keywords'))))),
            'countries' => array_values(array_unique(array_map('strtoupper', $clean($this->input('countries'))))),
            'registry_search' => [
                ...$registry,
                'sic_codes' => $clean($registry['sic_codes'] ?? []),
                'locations' => $clean($registry['locations'] ?? []),
            ],
            'agency' => [
                ...$agency,
                'countries' => array_values(array_unique(array_map('strtoupper', $clean($agency['countries'] ?? [])))),
                'job_keywords' => array_values(array_unique(array_map('mb_strtolower', $clean($agency['job_keywords'] ?? [])))),
                'job_countries' => array_values(array_unique(array_map('strtolower', $clean($agency['job_countries'] ?? [])))),
            ],
            'website_places' => [
                ...$places,
                'cities' => $clean($places['cities'] ?? []),
                'types' => array_values(array_filter(
                    $clean($places['types'] ?? []),
                    fn (string $t): bool => in_array($t, $allowedTypes, true),
                )),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'daily_qualified_target' => ['required', 'integer', 'min:1', 'max:500'],
            'qualify_threshold' => ['required', 'numeric', 'min:0', 'max:10'],
            'signal_max_age_days' => ['required', 'integer', 'min:1', 'max:90'],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['string', 'max:60'],
            'countries' => ['required', 'array', 'min:1', 'max:10'],
            'countries.*' => ['string', 'regex:/^[A-Z]{2}$/'],
            'caps' => ['required', 'array'],
            'caps.millionverifier_per_day' => ['required', 'integer', 'min:0', 'max:10000'],
            'caps.brave_per_day' => ['required', 'integer', 'min:0', 'max:10000'],
            'caps.adzuna_per_day' => ['required', 'integer', 'min:0', 'max:10000'],
            // Companies House allows 600 requests per 5 minutes per key.
            'caps.companies_house_per_5min' => ['required', 'integer', 'min:1', 'max:600'],
            'caps.crawler_pages_per_company' => ['required', 'integer', 'min:1', 'max:20'],
            // Companies House search source (D106); a per_day of 0 turns it off.
            'registry_search' => ['required', 'array'],
            'registry_search.per_day' => ['required', 'integer', 'min:0', 'max:200'],
            'registry_search.min_age_years' => ['required', 'integer', 'min:0', 'max:20'],
            'registry_search.sic_codes' => ['array', 'max:20'],
            'registry_search.sic_codes.*' => ['string', 'regex:/^\d{5}$/'],
            'registry_search.locations' => ['array', 'max:20'],
            'registry_search.locations.*' => ['string', 'max:60'],
            'laravel_partners_enabled' => ['required', 'boolean'],
            // Agency leads of any stack (D113, D117).
            'agency' => ['required', 'array'],
            'agency.per_day' => ['required', 'integer', 'min:0', 'max:500'],
            'agency.queries_per_day' => ['required', 'integer', 'min:0', 'max:100'],
            'agency.qualify_threshold' => ['required', 'numeric', 'min:0', 'max:10'],
            'agency.countries' => ['array', 'max:30'],
            'agency.countries.*' => ['string', Rule::in(array_keys((array) config('leadradar.agency_markets', [])))],
            'agency.jobs_per_day' => ['required', 'integer', 'min:0', 'max:500'],
            'agency.job_keywords' => ['array', 'max:20'],
            'agency.job_keywords.*' => ['string', 'max:60'],
            'agency.job_countries' => ['array', 'max:20'],
            'agency.job_countries.*' => ['string', Rule::in(['gb', 'us', 'ca', 'au', 'nz', 'nl', 'be', 'de', 'at', 'fr', 'es', 'it', 'pl', 'ch'])],
            'website_places' => ['required', 'array'],
            'website_places.per_day' => ['required', 'integer', 'min:0', 'max:200'],
            'website_places.min_reviews' => ['required', 'integer', 'min:1', 'max:500'],
            'website_places.cities' => ['array', 'max:30'],
            'website_places.cities.*' => ['string', 'max:60'],
            'website_places.types' => ['array', 'max:40'],
            'website_places.types.*' => ['string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'countries.*.regex' => 'Countries are two-letter codes such as GB.',
            'registry_search.sic_codes.*.regex' => 'SIC codes are five digits, such as 62012.',
        ];
    }
}
