<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Owner-tunable key/value settings (caps, targets, search terms). Values are JSON.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Defaults from docs/03. Seeded by SettingsSeeder and used when a key is missing.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'daily_qualified_target' => 50,
        'qualify_threshold' => 6.0,
        'cap.millionverifier.per_day' => 150,
        'cap.brave.per_day' => 150,
        'cap.adzuna.per_day' => 200,
        'cap.companies_house.per_5min' => 500,
        'cap.kvk.per_day' => 50, // paid basisprofielen (€0.02 each); searches are free
        'cap.opencorporates.per_day' => 15, // calls; the Essentials plan allows 500 a month
        'cap.crawler.pages_per_company' => 6,
        // Job-board keywords (D110). Keep Laravel-specific phrases; bare "inertia" / "filament" match unrelated trades.
        'search.keywords' => ['laravel', 'php laravel', 'laravel developer', 'livewire'],
        'search.countries' => ['GB'],
        'signal.max_age_days' => 30,
        // Companies House advanced search source (D106). New companies per London day; 0 turns the source off.
        'source.ch_sic.per_day' => 25,
        'source.ch_sic.sic_codes' => ['62012', '62020', '62090', '73110'],
        'source.ch_sic.locations' => ['Manchester', 'Leeds', 'Birmingham', 'Bristol', 'London'],
        'source.ch_sic.min_age_years' => 2,
        // Agency leads of any stack via web search (D113). New agencies per London day; 0 turns it off.
        'source.agency.per_day' => 60,
        'source.agency.queries_per_day' => 15,
        'source.agency.countries' => ['GB', 'US', 'CA', 'AU', 'IE', 'NZ', 'NL', 'BE', 'DK', 'SE', 'NO', 'FI', 'DE', 'AT', 'AE'],
        'source.agency.cursor' => 0,
        // Agency leads have no Laravel gate, so their own threshold (D116).
        'agency.qualify_threshold' => 4.5,
        // Owner goal: qualified agencies a day (D126, dashboard card).
        'agency.daily_goal' => 20,
        // Google Places as a second agency source (D128): runs alongside each agency web search when the key is set.
        'source.places_agency.enabled' => true,
        'source.places_agency.min_rating' => 4.0,
        'source.places_agency.min_reviews' => 5,
        // Businesses that may need agency services (D127). New businesses per day; 0 turns it off.
        'source.business.per_day' => 30,
        'source.business.queries_per_day' => 10,
        'source.business.countries' => ['AE'],
        'source.business.cursor' => 0,
        'business.qualify_threshold' => 4.0,
        // Job ads for any stack in every Adzuna market (D117): phrases, Adzuna country codes, new companies a day.
        'search.agency_keywords' => ['wordpress developer', 'shopify developer', 'woocommerce developer'],
        'search.agency_job_countries' => ['gb', 'us', 'ca', 'au', 'nz', 'nl', 'be', 'de', 'at'],
        'source.agency_jobs.per_day' => 40,
        // The position per SIC + location is kept in `source.ch_sic.cursor.{sic}|{location}` rows (FetchCompaniesHouseSicJob).
        // laravel.com/partners (P4.1, D108): one page request per discovery run.
        'source.laravel_partners.enabled' => true,
        // Website leads via Google Places (D111). 0 per_day turns the source off.
        'cap.google_places.per_day' => 100,
        'source.places.per_day' => 25,
        'source.places.min_reviews' => 30,
        'source.places.cities' => ['Manchester', 'Leeds', 'Birmingham', 'Bristol', 'London'],
        'source.places.types' => ['plumber', 'electrician', 'hair_salon', 'restaurant', 'dentist'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        return $setting === null ? ($default ?? self::DEFAULTS[$key] ?? null) : $setting->value;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
