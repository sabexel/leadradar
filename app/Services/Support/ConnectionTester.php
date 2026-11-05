<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\ExternalService;
use App\Models\Setting;
use App\Services\CompaniesHouse\CompaniesHouseService;
use App\Services\JobBoards\AdzunaService;
use App\Services\JobBoards\ReedService;
use App\Services\Search\BraveSearchService;
use App\Services\Verification\MillionVerifierService;
use Throwable;

/**
 * Settings page (docs/07 §6): is each API key configured, and does ONE cheap call work? Every call goes through the
 * service, so it is rate-limited, counted against the caps and logged in `api_usages` like any other (D95).
 * Key values are never returned — only "configured" / "missing" and a redacted error message.
 */
final class ConnectionTester
{
    /**
     * Services that need a key, with the config keys that must be set.
     */
    public const KEYS = [
        'companies_house' => ['services.companies_house.key'],
        'reed' => ['services.reed.key'],
        'adzuna' => ['services.adzuna.app_id', 'services.adzuna.app_key'],
        'millionverifier' => ['services.millionverifier.key'],
        'brave' => ['services.brave.key'],
    ];

    public function __construct(
        private readonly CompaniesHouseService $companiesHouse,
        private readonly ReedService $reed,
        private readonly AdzunaService $adzuna,
        private readonly MillionVerifierService $millionVerifier,
        private readonly BraveSearchService $brave,
    ) {}

    public function isConfigured(ExternalService $service): bool
    {
        $keys = self::KEYS[$service->value] ?? [];

        return $keys !== [] && collect($keys)->every(fn (string $key): bool => filled(config($key)));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(ExternalService $service): array
    {
        if (! array_key_exists($service->value, self::KEYS)) {
            return ['ok' => false, 'message' => "{$service->label()} needs no key."];
        }

        if (! $this->isConfigured($service)) {
            return ['ok' => false, 'message' => "{$service->label()}: key missing in .env."];
        }

        try {
            $detail = match ($service) {
                ExternalService::CompaniesHouse => $this->companiesHouse->searchCompanies('Companies House', 1)->count().' result(s) for a sample search',
                ExternalService::Reed => ($this->reed->search('laravel', 0, 1)->totalResults ?? 0).' Laravel jobs listed',
                ExternalService::Adzuna => ($this->adzuna->search('laravel', 1, 30, 1)->totalResults ?? 0).' Laravel jobs listed',
                ExternalService::MillionVerifier => $this->credits(),
                ExternalService::Brave => ($this->brave->findCompanyWebsite('Companies House') === null ? 'no' : 'a').' website found for a sample search',
                ExternalService::Crawler => '',
            };

            return ['ok' => true, 'message' => "{$service->label()} works: {$detail}."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => "{$service->label()} failed: ".$this->redact($e->getMessage(), $service)];
        }
    }

    /**
     * The balance check is free; it also refreshes the dashboard's balance (D86).
     */
    private function credits(): string
    {
        $credits = $this->millionVerifier->credits();
        Setting::put('millionverifier.credits', ['credits' => $credits, 'at' => now()->toIso8601String()]);

        return number_format($credits).' credits left';
    }

    /**
     * Belt and braces: a key must never reach the page, even inside an exception message.
     */
    private function redact(string $message, ExternalService $service): string
    {
        foreach (self::KEYS[$service->value] ?? [] as $key) {
            $value = (string) config($key);

            if (strlen($value) >= 4) {
                $message = str_replace([$value, rawurlencode($value)], '[redacted]', $message);
            }
        }

        return mb_substr($message, 0, 300);
    }
}
