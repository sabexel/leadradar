<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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

        $this->merge([
            'keywords' => array_values(array_unique(array_map('mb_strtolower', $clean($this->input('keywords'))))),
            'countries' => array_values(array_unique(array_map('strtoupper', $clean($this->input('countries'))))),
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'countries.*.regex' => 'Countries are two-letter codes such as GB.',
        ];
    }
}
