<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Enums\PageType;
use App\Models\Company;
use App\Models\CrawledPage;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Local: which pages to crawl, in which order, and what kind of page a URL is (docs/04).
 *
 * Home first → same-domain links matching the candidate pattern (best first) → sitemap.xml when the
 * home page offered none → stop at `cap.crawler.pages_per_company`.
 */
final class CrawlPlanner
{
    /**
     * Candidate patterns in priority order (contact details first — they matter most for outreach).
     */
    private const CANDIDATES = [
        PageType::Contact->value => 'contact',
        PageType::Team->value => 'team|people|our-people|meet-the',
        PageType::About->value => 'about',
        PageType::Careers->value => 'careers|jobs|vacancies|work-with-us|join-us|join-the-team',
        PageType::Services->value => 'services|what-we-do|laravel|case-stud|our-work|portfolio',
    ];

    private const SKIP_EXTENSIONS = '/\.(?:pdf|jpe?g|png|gif|svg|webp|zip|docx?|xlsx?|pptx?|mp4|mp3|css|js|xml|ico)$/i';

    public function homeUrl(Company $company): string
    {
        return rtrim((string) $company->website, '/').'/';
    }

    public function sitemapUrl(Company $company): string
    {
        return rtrim((string) $company->website, '/').'/sitemap.xml';
    }

    public function pageCap(): int
    {
        return max(1, (int) Setting::get('cap.crawler.pages_per_company'));
    }

    /**
     * The next URL to crawl for this company, or null when the crawl is complete.
     */
    public function next(Company $company): ?string
    {
        if ($company->website === null) {
            return null;
        }

        $pages = $company->crawledPages()->orderBy('id')->get();
        $done = $pages->pluck('url')->all();
        $fetched = $pages->filter(fn (CrawledPage $p): bool => ! str_ends_with($p->url, '/sitemap.xml'));

        if ($pages->isEmpty()) {
            return $this->homeUrl($company);
        }

        $home = $pages->first();

        // Robots disallows the home page → skip the whole site (docs/09).
        if (($home->extracted['skipped'] ?? null) === 'robots') {
            return null;
        }

        if ($fetched->count() >= $this->pageCap()) {
            return null;
        }

        $candidates = $this->rank(array_values(array_unique(array_filter(
            $pages->flatMap(fn (CrawledPage $p): array => $p->extracted['links'] ?? [])->all(),
            fn (mixed $url): bool => is_string($url),
        ))));

        foreach ($candidates as $url) {
            if (! in_array($url, $done, true)) {
                return $url;
            }
        }

        $sitemap = $this->sitemapUrl($company);

        return in_array($sitemap, $done, true) ? null : $sitemap;
    }

    /**
     * Same-domain, page-like candidate URLs from a page's links, best first, without fragments/queries.
     *
     * @param  list<array{href: string, text: string}>  $links
     * @return list<string>
     */
    public function candidateLinks(array $links, string $baseUrl, string $domain): array
    {
        $urls = [];

        foreach ($links as $link) {
            $url = $this->absolute($link['href'], $baseUrl);

            if ($url === null || ! $this->isSameDomain($url, $domain) || preg_match(self::SKIP_EXTENSIONS, (string) parse_url($url, PHP_URL_PATH)) === 1) {
                continue;
            }

            if ($this->pageTypeOf($url, $link['text']) !== PageType::Other && rtrim((string) parse_url($url, PHP_URL_PATH), '/') !== '') {
                $urls[] = $url;
            }
        }

        return $this->rank(array_values(array_unique($urls)));
    }

    public function pageTypeOf(string $url, string $linkText = ''): PageType
    {
        $path = Str::lower(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));

        if ($path === '') {
            return PageType::Home;
        }

        $haystack = $path.' '.Str::lower(Str::slug($linkText));

        foreach (self::CANDIDATES as $type => $pattern) {
            if (preg_match('/(?:'.$pattern.')/', $haystack) === 1) {
                return PageType::from($type);
            }
        }

        return PageType::Other;
    }

    public function isSameDomain(string $url, string $domain): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);

        return $host === $domain;
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function rank(array $urls): array
    {
        $order = array_flip(array_keys(self::CANDIDATES));

        usort($urls, fn (string $a, string $b): int => [($order[$this->pageTypeOf($a)->value] ?? 99), strlen($a)]
            <=> [($order[$this->pageTypeOf($b)->value] ?? 99), strlen($b)]);

        return $urls;
    }

    private function absolute(string $href, string $baseUrl): ?string
    {
        $href = trim($href);

        if ($href === '' || preg_match('/^(?:mailto|tel|javascript|data):/i', $href) === 1 || str_starts_with($href, '#')) {
            return null;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? null;

        if ($host === null) {
            return null;
        }

        $url = match (true) {
            str_starts_with($href, '//') => "{$scheme}:{$href}",
            preg_match('#^https?://#i', $href) === 1 => $href,
            str_starts_with($href, '/') => "{$scheme}://{$host}{$href}",
            // Relative to the current directory: "/about/team" + "jane" → "/about/jane".
            default => "{$scheme}://{$host}".substr($base['path'] ?? '/', 0, (int) strrpos($base['path'] ?? '/', '/') + 1).$href,
        };

        // Drop query strings and fragments: they are the same page for our purposes.
        $parts = parse_url($url);

        if (! isset($parts['host'])) {
            return null;
        }

        return Str::lower(($parts['scheme'] ?? 'https').'://'.$parts['host']).($parts['path'] ?? '/');
    }
}
