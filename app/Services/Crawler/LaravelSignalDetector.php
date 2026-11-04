<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\DTOs\Classification\DetectedSignal;
use App\Enums\PageType;
use App\Enums\SignalWeight;
use Illuminate\Support\Str;

/**
 * Local: evidence that a company works with Laravel (docs/04 table).
 */
final class LaravelSignalDetector
{
    private const OTHER_FRAMEWORKS = ['symfony', 'codeigniter', 'yii', 'cakephp', 'zend', 'laminas', 'slim', 'phalcon', 'django', 'rails', 'express', 'spring'];

    /**
     * @param  list<string>  $cookieNames  names from the response's Set-Cookie headers
     * @return list<DetectedSignal>
     */
    public function detect(string $html, string $text, PageType $pageType, string $url, array $cookieNames = []): array
    {
        $signals = [];
        $lowerText = Str::lower($text);
        $lowerHtml = Str::lower($html);

        if (preg_match('/\blaravel\b/', $lowerText) === 1) {
            $signals[] = $this->mentionSignal($lowerText, $pageType, $url);
        }

        if (str_contains($lowerHtml, 'laravel.com/partners')) {
            $signals[] = new DetectedSignal('laravel_partner', SignalWeight::Strong, 'Links to laravel.com/partners', $url);
        }

        $cookies = array_map('strtolower', $cookieNames);

        if (in_array('laravel_session', $cookies, true) || in_array('xsrf-token', $cookies, true)) {
            $signals[] = new DetectedSignal('runs_laravel', SignalWeight::Medium, 'Site sets a Laravel session/XSRF cookie', $url);
        }

        if (str_contains($lowerHtml, 'livewire.js') || str_contains($lowerHtml, 'livewire.min.js') || preg_match('/\swire:(?:click|model|submit|loading|poll|navigate)/', $lowerHtml) === 1) {
            $signals[] = new DetectedSignal('livewire', SignalWeight::Medium, 'Site uses Livewire', $url);
        }

        if (preg_match('/<div[^>]+id=["\']app["\'][^>]*\sdata-page=|<div[^>]+data-page=["\'][^"\']*&quot;component&quot;/', $lowerHtml) === 1) {
            $signals[] = new DetectedSignal('inertia', SignalWeight::Medium, 'Site is an Inertia app', $url);
        }

        return $signals;
    }

    /**
     * "Laravel" on a services / case-study / careers page is strong; inside a list of PHP frameworks it is weak;
     * anywhere else it is medium (D57).
     */
    private function mentionSignal(string $lowerText, PageType $pageType, string $url): DetectedSignal
    {
        $others = array_filter(self::OTHER_FRAMEWORKS, fn (string $f): bool => preg_match('/\b'.$f.'\b/', $lowerText) === 1);

        if (count($others) >= 2) {
            return new DetectedSignal('framework_list', SignalWeight::Weak, 'Laravel listed with '.implode(', ', $others), $url);
        }

        $strongPage = in_array($pageType, [PageType::Services, PageType::Careers], true)
            || preg_match('#/(?:case-stud|work|portfolio|projects|laravel)#', Str::lower((string) parse_url($url, PHP_URL_PATH))) === 1;

        return $strongPage
            ? new DetectedSignal('laravel_mention', SignalWeight::Strong, "\"Laravel\" on {$pageType->value} page", $url)
            : new DetectedSignal('laravel_mention', SignalWeight::Medium, "\"Laravel\" on {$pageType->value} page", $url);
    }
}
