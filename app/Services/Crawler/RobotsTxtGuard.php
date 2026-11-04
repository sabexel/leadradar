<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Local: robots.txt parsing + per-domain cache (24 h) + per-domain politeness clock.
 * The robots.txt fetch itself is `WebsiteCrawlerService::fetchRobotsTxt()` (the only HTTP).
 */
final class RobotsTxtGuard
{
    /**
     * Our product token, matched against `User-agent:` lines (case-insensitive).
     */
    public const AGENT_TOKEN = 'leadradarbot';

    public function parse(string $body): RobotsRules
    {
        $ours = null;      // group addressed to LeadRadarBot
        $wildcard = null;  // group for "*"

        /** @var list<string> $agents */
        $agents = [];
        /** @var list<array{allow: bool, pattern: string}> $rules */
        $rules = [];
        $delay = null;
        $lastWasAgent = false;

        $flush = function () use (&$agents, &$rules, &$delay, &$ours, &$wildcard): void {
            if ($agents === []) {
                return;
            }

            $group = new RobotsRules($rules, $delay);

            foreach ($agents as $agent) {
                if ($agent !== '*' && $agent !== '' && str_contains(self::AGENT_TOKEN, $agent)) {
                    $ours ??= $group;
                } elseif ($agent === '*') {
                    $wildcard ??= $group;
                }
            }

            [$agents, $rules, $delay] = [[], [], null];
        };

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = explode(':', $line, 2);
            $field = Str::lower(trim($field));
            $value = trim($value);

            if ($field === 'user-agent') {
                // Consecutive user-agent lines share one group; a new run starts a new group.
                if (! $lastWasAgent) {
                    $flush();
                }

                $agents[] = Str::lower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;

            if ($agents === []) {
                continue; // rules before any user-agent line belong to no group
            }

            if (($field === 'allow' || $field === 'disallow') && $value !== '') {
                $rules[] = ['allow' => $field === 'allow', 'pattern' => $value]; // empty "Disallow:" allows all
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $delay = (int) ceil((float) $value);
            }
        }

        $flush();

        return $ours ?? $wildcard ?? RobotsRules::allowAll();
    }

    /**
     * Rules for an HTTP outcome (RFC 9309): 2xx → parse; 4xx → allow all; 5xx/unreachable → disallow all.
     */
    public function rulesForResponse(?int $status, ?string $body): RobotsRules
    {
        return match (true) {
            $status !== null && $status >= 200 && $status < 300 => $this->parse((string) $body),
            $status !== null && $status >= 400 && $status < 500 => RobotsRules::allowAll(),
            default => RobotsRules::disallowAll(),
        };
    }

    public function cached(string $domain): ?RobotsRules
    {
        $data = Cache::get($this->rulesKey($domain));

        return is_array($data) ? RobotsRules::fromArray($data) : null;
    }

    public function remember(string $domain, RobotsRules $rules): void
    {
        Cache::put($this->rulesKey($domain), $rules->toArray(), now()->addHours((int) config('services.crawler.robots_cache_hours', 24)));
    }

    /**
     * Seconds to wait before the next request to this domain (≥ 2 s apart, or the site's Crawl-delay).
     */
    public function secondsUntilAllowed(string $domain): int
    {
        $last = Cache::get($this->clockKey($domain));

        if (! is_int($last)) {
            return 0;
        }

        $rules = $this->cached($domain);
        $gap = max((int) config('services.crawler.min_seconds_between_requests', 2), $rules === null ? 0 : (int) $rules->crawlDelay);

        return max(0, $last + $gap - CarbonImmutable::now()->getTimestamp());
    }

    public function markRequested(string $domain): void
    {
        Cache::put($this->clockKey($domain), CarbonImmutable::now()->getTimestamp(), now()->addMinutes(10));
    }

    private function rulesKey(string $domain): string
    {
        return "crawler:robots:{$domain}";
    }

    private function clockKey(string $domain): string
    {
        return "crawler:last-request:{$domain}";
    }
}
