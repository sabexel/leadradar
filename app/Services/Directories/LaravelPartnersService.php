<?php

declare(strict_types=1);

namespace App\Services\Directories;

use App\DTOs\Directories\LaravelPartner;
use App\Enums\ExternalService;
use App\Exceptions\ExternalServiceException;
use App\Services\Crawler\RobotsRules;
use App\Services\Crawler\RobotsTxtGuard;
use App\Services\Support\ApiUsageLimiter;
use App\Services\Support\ExternalApiService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The official Laravel Partners directory (P4.1, D108). One request per method call, logged as crawler usage;
 * the crawler's User-Agent and robots.txt rules apply (docs/09).
 *
 * The page is an Inertia app: the whole partner list is in the page JSON (`<script data-page="app">`,
 * `props.partners`), so one request reads every partner — no per-partner profile pages are fetched.
 */
final class LaravelPartnersService extends ExternalApiService
{
    public function __construct(ApiUsageLimiter $limiter, private readonly RobotsTxtGuard $robots)
    {
        parent::__construct($limiter);
    }

    protected function service(): ExternalService
    {
        return ExternalService::Crawler;
    }

    protected function client(): PendingRequest
    {
        return Http::withUserAgent((string) config('services.crawler.user_agent'))
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language' => 'en-GB,en;q=0.8'])
            ->timeout((int) config('services.crawler.timeout', 10))
            ->connectTimeout(5);
    }

    public function host(): string
    {
        return (string) parse_url($this->url(), PHP_URL_HOST);
    }

    public function fetchRobotsTxt(): RobotsRules
    {
        $response = $this->get($this->origin().'/robots.txt', [], 'robots.txt', 0.0, allowErrors: true);
        $rules = $this->robots->rulesForResponse($response->status(), $response->body());

        $this->robots->markRequested($this->host());
        $this->robots->remember($this->host(), $rules);

        return $rules;
    }

    /**
     * Every listed partner; empty when robots.txt (fetched first by the caller) disallows the page.
     *
     * @return Collection<int, LaravelPartner>
     *
     * @throws ExternalServiceException when the page can't be read or its partner data is missing
     */
    public function listPartners(): Collection
    {
        $rules = $this->robots->cached($this->host()) ?? RobotsRules::disallowAll();

        if (! $rules->allows((string) (parse_url($this->url(), PHP_URL_PATH) ?: '/'))) {
            return collect();
        }

        $response = $this->get($this->url(), [], 'laravel_partners', 0.0);
        $this->robots->markRequested($this->host());

        $body = substr($response->body(), 0, (int) config('services.crawler.max_body_bytes', 2 * 1024 * 1024));

        if (preg_match('#<script[^>]*\bdata-page="app"[^>]*>(.*?)</script>#s', $body, $match) !== 1) {
            throw new ExternalServiceException($this->service(), 'laravel_partners page has no Inertia page data');
        }

        $data = json_decode($match[1], true);
        $partners = is_array($data) ? ($data['props']['partners'] ?? null) : null;

        if (! is_array($partners)) {
            throw new ExternalServiceException($this->service(), 'laravel_partners page data has no partner list');
        }

        return collect($partners)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): ?LaravelPartner => $this->partner($item))
            ->filter()
            ->values();
    }

    /**
     * @param  array<mixed>  $item
     */
    private function partner(array $item): ?LaravelPartner
    {
        $slug = is_string($item['slug'] ?? null) ? trim($item['slug']) : '';
        $name = is_string($item['name'] ?? null) ? Str::squish($item['name']) : '';

        if ($slug === '' || $name === '' || preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return null;
        }

        $website = is_string($item['website'] ?? null) && preg_match('#^https?://#i', $item['website']) === 1 ? trim($item['website']) : null;
        $regions = array_values(collect(is_array($item['regions'] ?? null) ? $item['regions'] : [])
            ->map(fn (mixed $region): ?string => is_array($region) && is_string($region['slug'] ?? null) ? $region['slug'] : null)
            ->filter()
            ->all());

        return new LaravelPartner(
            slug: $slug,
            name: $name,
            tier: is_string($item['tier'] ?? null) ? $item['tier'] : null,
            website: $website,
            regions: $regions,
            description: is_string($item['description'] ?? null) ? Str::squish(html_entity_decode(strip_tags($item['description']), ENT_QUOTES | ENT_HTML5)) : '',
            profileUrl: rtrim($this->url(), '/').'/'.$slug,
        );
    }

    private function url(): string
    {
        return (string) config('services.laravel_partners.url');
    }

    private function origin(): string
    {
        return (string) parse_url($this->url(), PHP_URL_SCHEME).'://'.$this->host();
    }
}
