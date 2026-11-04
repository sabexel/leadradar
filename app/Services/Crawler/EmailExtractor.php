<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\DTOs\Crawl\ExtractedEmail;
use App\Enums\EmailKind;
use Illuminate\Support\Str;

/**
 * Local: emails on a page that belong to the company's own domain (docs/04).
 *
 * Finds `mailto:` links, plain-text addresses and bracketed obfuscations ("jane [at] example [dot] com").
 * Kind here is contact / careers / other; matching an `other` address to a founder (→ person) needs the
 * people list and happens in ApplyCrawlResultAction.
 */
final class EmailExtractor
{
    private const CONTACT = ['hello', 'hi', 'info', 'contact', 'enquiries', 'enquiry', 'office', 'studio', 'team', 'mail', 'admin', 'sales', 'support', 'help', 'general'];

    private const CAREERS = ['careers', 'career', 'jobs', 'job', 'recruitment', 'recruiting', 'hr', 'hiring', 'work', 'talent', 'join', 'joinus', 'people'];

    private const IGNORE = ['noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster', 'privacy', 'gdpr', 'dpo', 'abuse', 'webmaster', 'example', 'test', 'email', 'name', 'yourname', 'you'];

    private const EMAIL = '[a-z0-9][a-z0-9._%+\-]{0,63}@(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}';

    /**
     * @return list<ExtractedEmail>
     */
    public function extract(string $html, string $text, string $companyDomain, string $sourceUrl): array
    {
        $found = [];

        // mailto: links (may carry ?subject=…, may be URL-encoded)
        if (preg_match_all('/mailto:([^"\'?\s>]+)/i', $html, $m) > 0) {
            array_push($found, ...array_map(fn (string $e): string => rawurldecode($e), $m[1]));
        }

        $plain = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        if (preg_match_all('/\b'.self::EMAIL.'\b/i', $plain, $m) > 0) {
            array_push($found, ...$m[0]);
        }

        // "jane [at] example [dot] co [dot] uk", "jane (at) example.co.uk", "jane{at}example{dot}com"
        $obfuscated = '/\b([a-z0-9][a-z0-9._%+\-]{0,63})\s*[\[({]\s*at\s*[\])}]\s*([a-z0-9\-]+(?:\s*(?:[\[({]\s*dot\s*[\])}]|\.)\s*[a-z0-9\-]+)+)/i';

        if (preg_match_all($obfuscated, $plain, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $domain = (string) preg_replace('/\s*(?:[\[({]\s*dot\s*[\])}]|\.)\s*/i', '.', $match[2]);
                $found[] = $match[1].'@'.$domain;
            }
        }

        $emails = [];

        foreach ($found as $candidate) {
            $email = Str::lower(trim($candidate, " \t\n\r\0\x0B.,;:"));

            if (! $this->isCompanyEmail($email, $companyDomain) || isset($emails[$email])) {
                continue;
            }

            $kind = $this->kindOf(explode('@', $email)[0]);

            if ($kind !== null) {
                $emails[$email] = new ExtractedEmail($email, $kind, $sourceUrl);
            }
        }

        return array_values($emails);
    }

    /**
     * Same domain, or a subdomain of it (mail.example.co.uk). Third-party addresses are dropped.
     */
    public function isCompanyEmail(string $email, string $companyDomain): bool
    {
        if (preg_match('/^'.self::EMAIL.'$/i', $email) !== 1) {
            return false;
        }

        $domain = explode('@', $email)[1];
        $companyDomain = Str::lower((string) preg_replace('/^www\./', '', $companyDomain));

        return $domain === $companyDomain || str_ends_with($domain, '.'.$companyDomain);
    }

    /**
     * Null for addresses we never want (no-reply, privacy, placeholders).
     */
    private function kindOf(string $localPart): ?EmailKind
    {
        $normalised = str_replace(['.', '_', '-'], '', $localPart);

        if (in_array($localPart, self::IGNORE, true) || in_array($normalised, self::IGNORE, true)) {
            return null;
        }

        return match (true) {
            in_array($localPart, self::CONTACT, true) => EmailKind::Contact,
            in_array($localPart, self::CAREERS, true), in_array($normalised, self::CAREERS, true) => EmailKind::Careers,
            default => EmailKind::Other,
        };
    }
}
