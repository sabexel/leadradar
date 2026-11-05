<?php

declare(strict_types=1);

use App\Enums\ExternalService;
use App\Models\ApiUsage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * @return array<string, mixed>
 */
function validSettings(array $overrides = []): array
{
    return array_replace_recursive([
        'daily_qualified_target' => 40,
        'qualify_threshold' => 6.5,
        'signal_max_age_days' => 21,
        'keywords' => ['Laravel', ' livewire ', '', 'laravel'],
        'countries' => ['gb', 'GB'],
        'caps' => [
            'millionverifier_per_day' => 120,
            'brave_per_day' => 100,
            'adzuna_per_day' => 200,
            'companies_house_per_5min' => 450,
            'crawler_pages_per_company' => 5,
        ],
    ], $overrides);
}

it('redirects guests', function () {
    auth()->logout();

    $this->get(route('configuration.edit'))->assertRedirect(route('login'));
});

it('shows the settings and which keys are configured, never their values', function () {
    config([
        'services.companies_house.key' => 'secret-ch-key-123',
        'services.reed.key' => null,
        'services.adzuna.app_id' => 'id-only',
        'services.adzuna.app_key' => null,
        'services.millionverifier.key' => 'secret-mv-key-456',
        'services.brave.key' => '',
    ]);

    $response = $this->get(route('configuration.edit'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('configuration/edit')
        ->where('settings.daily_qualified_target', 50)
        ->where('settings.qualify_threshold', 6)
        ->where('settings.keywords', ['laravel'])
        ->where('settings.caps.millionverifier_per_day', 150)
        ->where('services', [
            ['value' => 'companies_house', 'label' => 'Companies House', 'configured' => true],
            ['value' => 'reed', 'label' => 'Reed', 'configured' => false],
            ['value' => 'adzuna', 'label' => 'Adzuna', 'configured' => false],
            ['value' => 'millionverifier', 'label' => 'MillionVerifier', 'configured' => true],
            ['value' => 'brave', 'label' => 'Brave Search', 'configured' => false],
        ]));

    expect($response->getContent())->not->toContain('secret-ch-key-123')->not->toContain('secret-mv-key-456');
});

it('saves cleaned settings', function () {
    $this->put(route('configuration.update'), validSettings())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Setting::get('daily_qualified_target'))->toBe(40)
        ->and(Setting::get('qualify_threshold'))->toEqual(6.5)
        ->and(Setting::get('signal.max_age_days'))->toBe(21)
        ->and(Setting::get('search.keywords'))->toBe(['laravel', 'livewire'])
        ->and(Setting::get('search.countries'))->toBe(['GB'])
        ->and(Setting::get('cap.millionverifier.per_day'))->toBe(120)
        ->and(Setting::get('cap.companies_house.per_5min'))->toBe(450)
        ->and(Setting::get('cap.crawler.pages_per_company'))->toBe(5);
});

it('refuses settings out of range', function (array $overrides, string $field) {
    $payload = validSettings($overrides);
    if (array_key_exists('keywords', $overrides)) {
        $payload['keywords'] = $overrides['keywords']; // a list replaces, it does not merge
    }

    $this->put(route('configuration.update'), $payload)->assertSessionHasErrors($field);

    expect(Setting::query()->where('key', 'daily_qualified_target')->exists())->toBeFalse();
})->with([
    'target 0' => [['daily_qualified_target' => 0], 'daily_qualified_target'],
    'threshold 11' => [['qualify_threshold' => 11], 'qualify_threshold'],
    'no keywords' => [['keywords' => ['', ' ']], 'keywords'],
    'bad country' => [['countries' => ['GBR']], 'countries.0'],
    'CH over its limit' => [['caps' => ['companies_house_per_5min' => 601]], 'caps.companies_house_per_5min'],
    'negative cap' => [['caps' => ['brave_per_day' => -1]], 'caps.brave_per_day'],
    'signal age 0' => [['signal_max_age_days' => 0], 'signal_max_age_days'],
]);

// --- Test connection --------------------------------------------------------------------

it('tests MillionVerifier with the free balance call, logs it and refreshes the dashboard balance', function () {
    config(['services.millionverifier.key' => 'test-mv-key']);
    Http::fake(['*' => Http::response(['credits' => 4321])]);

    $this->post(route('configuration.test'), ['service' => 'millionverifier'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'MillionVerifier works: 4,321 credits left.');

    Http::assertSentCount(1);
    expect(ApiUsage::query()->sole()->service)->toBe(ExternalService::MillionVerifier)
        ->and(Setting::get('millionverifier.credits')['credits'])->toBe(4321);
});

it('tests Companies House with one search', function () {
    config(['services.companies_house.key' => 'test-ch-key']);
    Http::fake(['*' => Http::response(jsonFixture('companies-house/search-exact.json'))]);

    $this->post(route('configuration.test'), ['service' => 'companies_house'])->assertInertiaFlash('toast.type', 'success');

    Http::assertSentCount(1);
    expect(ApiUsage::count())->toBe(1);
});

it('reports a failing service without leaking its key', function () {
    config(['services.brave.key' => 'super-secret-brave-key']);
    Http::fake(['*' => Http::response(['message' => 'Invalid token super-secret-brave-key'], 401)]);

    $this->post(route('configuration.test'), ['service' => 'brave'])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Brave Search failed: [brave] /web/search returned HTTP 401');
});

it('makes no call for a missing key or an unknown service', function () {
    config(['services.reed.key' => null]);
    Http::fake();

    $this->post(route('configuration.test'), ['service' => 'reed'])
        ->assertInertiaFlash('toast.message', 'Reed: key missing in .env.');
    $this->post(route('configuration.test'), ['service' => 'crawler'])->assertSessionHasErrors('service');

    Http::assertNothingSent();
});
