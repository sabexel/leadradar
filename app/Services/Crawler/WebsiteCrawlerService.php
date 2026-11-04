<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\DTOs\Crawl\CrawlResult;
use App\Enums\ExternalService;
use App\Enums\PageType;
use App\Models\Company;
use App\Services\Classification\CompanyClassifier;
use App\Services\Support\ApiUsageLimiter;
use App\Services\Support\ExternalApiService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Polite crawler for company websites (docs/04, docs/09). One HTTP request per method call.
 *
 * Identifies itself with `services.crawler.user_agent`, obeys robots.txt (checked by the caller via
 * RobotsTxtGuard before every page), stays on the company's domain, 10 s timeout, 2 MB body cap,
 * no JavaScript, no forms, no cookies sent. Returns extracted facts only — never the HTML.
 */
final class WebsiteCrawlerService extends ExternalApiService
{
    public function __construct(
        ApiUsageLimiter $limiter,
        private readonly RobotsTxtGuard $robots,
        private readonly CrawlPlanner $planner,
        private readonly EmailExtractor $emails,
        private readonly LaravelSignalDetector $laravel,
        private readonly OffshoreSignalDetector $offshore,
        private readonly CompanyClassifier $classifier,
    ) {
        parent::__construct($limiter);
    }

    protected function service(): ExternalService
    {
        return ExternalService::Crawler;
    }

    protected function client(): PendingRequest
    {
        return Http::withUserAgent((string) config('services.crawler.user_agent'))
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5', 'Accept-Language' => 'en-GB,en;q=0.8'])
            ->timeout((int) config('services.crawler.timeout', 10))
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => ['max' => 3, 'track_redirects' => true]]);
    }

    /**
     * Fetch, parse and cache robots.txt for the company's domain.
     */
    public function fetchRobotsTxt(Company $company): RobotsRules
    {
        $response = $this->get(rtrim((string) $company->website, '/').'/robots.txt', [], 'robots.txt', 0.0, allowErrors: true);
        $rules = $this->robots->rulesForResponse($response->status(), $response->body());

        $this->robots->markRequested((string) $company->domain);
        $this->robots->remember((string) $company->domain, $rules);

        return $rules;
    }

    /**
     * Fetch ONE page and extract facts. Disallowed or off-domain URLs are skipped without a request.
     */
    public function fetchPage(Company $company, string $url): CrawlResult
    {
        $domain = (string) $company->domain;
        $pageType = $this->planner->pageTypeOf($url);
        $rules = $this->robots->cached($domain) ?? RobotsRules::disallowAll();

        if (! $this->planner->isSameDomain($url, $domain)) {
            return CrawlResult::skipped($url, $pageType, 'off_domain');
        }

        if (! $rules->allows((string) (parse_url($url, PHP_URL_PATH) ?: '/'))) {
            return CrawlResult::skipped($url, $pageType, 'robots');
        }

        $response = $this->get($url, [], 'page', 0.0, allowErrors: true);
        $this->robots->markRequested($domain);

        if (! $response->successful()) {
            return CrawlResult::skipped($url, $pageType, 'http_error', $response->status());
        }

        $finalUrl = $this->finalUrl($response, $url);

        if (! $this->planner->isSameDomain($finalUrl, $domain)) {
            return CrawlResult::skipped($url, $pageType, 'redirected_off_domain', $response->status());
        }

        $body = substr($response->body(), 0, (int) config('services.crawler.max_body_bytes', 2 * 1024 * 1024));
        $contentType = Str::lower((string) $response->header('Content-Type'));

        if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/sitemap.xml')) {
            return $this->sitemapResult($url, $finalUrl, $response->status(), $body, $domain);
        }

        if (! str_contains($contentType, 'html')) {
            return CrawlResult::skipped($url, $pageType, 'not_html', $response->status());
        }

        return $this->htmlResult($url, $finalUrl, $response, $body, $domain, $pageType);
    }

    private function htmlResult(string $url, string $finalUrl, Response $response, string $html, string $domain, PageType $pageType): CrawlResult
    {
        try {
            $dom = new Crawler($html, $finalUrl);
            $dom->filter('script, style, noscript, template, svg')->each(function (Crawler $node): void {
                foreach ($node as $element) {
                    $element->parentNode?->removeChild($element);
                }
            });

            $title = $dom->filter('title')->count() > 0 ? Str::squish($dom->filter('title')->first()->text('')) : null;
            $text = $dom->filter('body')->count() > 0 ? Str::squish($dom->filter('body')->first()->text('')) : Str::squish(strip_tags($html));
            $links = $dom->filter('a[href]')->each(fn (Crawler $a): array => [
                'href' => (string) $a->attr('href'),
                'text' => Str::squish($a->text('')),
            ]);
        } catch (Throwable) {
            $title = null;
            $text = Str::squish(strip_tags($html));
            $links = [];
        }

        return new CrawlResult(
            url: $url,
            finalUrl: $finalUrl,
            status: $response->status(),
            pageType: $pageType,
            title: $title === '' ? null : Str::limit((string) $title, 200),
            contentHash: hash('sha256', $html),
            emails: $this->emails->extract($html, $text, $domain, $finalUrl),
            links: $this->planner->candidateLinks($links, $finalUrl, $domain),
            laravelSignals: $this->laravel->detect($html, $text, $pageType, $finalUrl, $this->cookieNames($response)),
            offshoreSignals: $this->offshore->detectInText($text, $finalUrl),
            agencyTerms: $this->classifier->agencyTerms($text),
        );
    }

    private function sitemapResult(string $url, string $finalUrl, int $status, string $xml, string $domain): CrawlResult
    {
        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m);

        $links = array_map(fn (string $loc): array => ['href' => html_entity_decode($loc), 'text' => ''], array_slice($m[1], 0, 500));

        return new CrawlResult(
            url: $url,
            finalUrl: $finalUrl,
            status: $status,
            pageType: PageType::Other,
            contentHash: hash('sha256', $xml),
            links: $this->planner->candidateLinks($links, $finalUrl, $domain),
        );
    }

    private function finalUrl(Response $response, string $requested): string
    {
        $history = $response->header('X-Guzzle-Redirect-History');

        if ($history !== '') {
            $hops = array_map('trim', explode(',', $history));

            return (string) end($hops);
        }

        return (string) ($response->effectiveUri() ?? $requested);
    }

    /**
     * @return list<string>
     */
    private function cookieNames(Response $response): array
    {
        return array_values(array_filter(array_map(
            fn (string $cookie): string => trim(explode('=', $cookie, 2)[0]),
            $response->toPsrResponse()->getHeader('Set-Cookie'),
        )));
    }
}
