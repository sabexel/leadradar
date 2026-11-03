<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Inserts missing settings with their defaults. Never overwrites values the owner changed.
 * Safe to run in production: `php artisan db:seed --class=SettingsSeeder --force`.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Setting::DEFAULTS as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
