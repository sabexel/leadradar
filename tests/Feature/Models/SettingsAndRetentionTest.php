<?php

declare(strict_types=1);

use App\Enums\OutreachStatus;
use App\Models\ApiUsage;
use App\Models\Company;
use App\Models\Setting;
use App\Models\Suppression;
use Database\Seeders\SettingsSeeder;

it('seeds every default setting', function () {
    $this->seed(SettingsSeeder::class);

    expect(Setting::count())->toBe(count(Setting::DEFAULTS))
        ->and(Setting::get('daily_qualified_target'))->toBe(50)
        ->and(Setting::get('qualify_threshold'))->toEqual(6.0)
        ->and(Setting::get('search.keywords'))->toBe(['laravel']);
});

it('does not overwrite settings the owner changed when reseeding', function () {
    Setting::put('daily_qualified_target', 20);

    $this->seed(SettingsSeeder::class);

    expect(Setting::get('daily_qualified_target'))->toBe(20);
});

it('falls back to the documented default when a setting is missing', function () {
    expect(Setting::get('cap.brave.per_day'))->toBe(150)
        ->and(Setting::get('unknown.key', 'fallback'))->toBe('fallback');
});

it('stores suppression values lowercased', function () {
    Suppression::factory()->create(['value' => 'Example.TEST']);

    expect(Suppression::first()->value)->toBe('example.test');
});

it('prunes only never-contacted companies idle for 12 months', function () {
    $stale = Company::factory()->create();
    $contacted = Company::factory()->create(['outreach_status' => OutreachStatus::Contacted]);
    $fresh = Company::factory()->create();

    Company::query()->whereKey([$stale->id, $contacted->id])->update(['updated_at' => now()->subMonths(13)]);

    $this->artisan('model:prune', ['--model' => [Company::class]])->assertSuccessful();

    expect(Company::pluck('id')->all())->toEqualCanonicalizing([$contacted->id, $fresh->id]);
});

it('prunes api usage older than 180 days', function () {
    ApiUsage::factory()->create(['date' => now()->subDays(200)]);
    ApiUsage::factory()->create(['date' => today()]);

    $this->artisan('model:prune', ['--model' => [ApiUsage::class]])->assertSuccessful();

    expect(ApiUsage::count())->toBe(1);
});
