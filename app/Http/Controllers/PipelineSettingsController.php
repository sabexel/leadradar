<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExternalService;
use App\Http\Requests\UpdatePipelineSettingsRequest;
use App\Models\Setting;
use App\Services\Support\ConnectionTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * LeadRadar settings (docs/07 §6). Lives at /configuration because the starter kit owns /settings/* (D94).
 */
final class PipelineSettingsController extends Controller
{
    /**
     * Form field → settings key.
     */
    private const CAPS = [
        'millionverifier_per_day' => 'cap.millionverifier.per_day',
        'brave_per_day' => 'cap.brave.per_day',
        'adzuna_per_day' => 'cap.adzuna.per_day',
        'companies_house_per_5min' => 'cap.companies_house.per_5min',
        'crawler_pages_per_company' => 'cap.crawler.pages_per_company',
    ];

    public function edit(ConnectionTester $tester): Response
    {
        $caps = [];
        foreach (self::CAPS as $field => $key) {
            $caps[$field] = (int) Setting::get($key);
        }

        return Inertia::render('configuration/edit', [
            'settings' => [
                'daily_qualified_target' => (int) Setting::get('daily_qualified_target'),
                'qualify_threshold' => (float) Setting::get('qualify_threshold'),
                'signal_max_age_days' => (int) Setting::get('signal.max_age_days'),
                'keywords' => array_values((array) Setting::get('search.keywords')),
                'countries' => array_values((array) Setting::get('search.countries')),
                'caps' => $caps,
            ],
            'services' => array_map(fn (string $service): array => [
                'value' => $service,
                'label' => ExternalService::from($service)->label(),
                'configured' => $tester->isConfigured(ExternalService::from($service)),
            ], array_keys(ConnectionTester::KEYS)),
        ]);
    }

    public function update(UpdatePipelineSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        Setting::put('daily_qualified_target', (int) $data['daily_qualified_target']);
        Setting::put('qualify_threshold', round((float) $data['qualify_threshold'], 1));
        Setting::put('signal.max_age_days', (int) $data['signal_max_age_days']);
        Setting::put('search.keywords', array_values($data['keywords']));
        Setting::put('search.countries', array_values($data['countries']));

        foreach (self::CAPS as $field => $key) {
            Setting::put($key, (int) $data['caps'][$field]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settings saved. Run php artisan leadradar:rescore if you changed the threshold.']);

        return back();
    }

    /**
     * One cheap, logged call to the service (docs/07 §6). Never shows a key.
     */
    public function test(Request $request, ConnectionTester $tester): RedirectResponse
    {
        $validated = $request->validate(['service' => ['required', Rule::in(array_keys(ConnectionTester::KEYS))]]);
        $result = $tester->test(ExternalService::from((string) $validated['service']));

        Inertia::flash('toast', ['type' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }
}
