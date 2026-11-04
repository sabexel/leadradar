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
        'cap.crawler.pages_per_company' => 6,
        'search.keywords' => ['laravel'],
        'search.countries' => ['GB'],
        'signal.max_age_days' => 30,
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
