<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Enums\PageType;
use Illuminate\Support\Str;

/**
 * Local: which web technologies a company's site runs on, or offers as a service (D114). Used for agency leads of
 * any stack — the stack is a label and a score line, never a gate.
 */
final class TechStackDetector
{
    /**
     * Fingerprints of the platform the page itself is built on.
     */
    private const BUILT_WITH = [
        'wordpress' => ['/wp-content/', '/wp-includes/', 'name="generator" content="wordpress', '/wp-json/'],
        'woocommerce' => ['woocommerce'],
        'shopify' => ['cdn.shopify.com', 'shopify.theme', 'myshopify.com'],
        'webflow' => ['data-wf-site', 'webflow.js', 'assets.website-files.com'],
        'wix' => ['static.wixstatic.com', 'wix.com website builder'],
        'squarespace' => ['static1.squarespace.com', 'squarespace.com'],
        'drupal' => ['drupal.settings', '/sites/default/files/', 'name="generator" content="drupal'],
        'magento' => ['mage/cookies', 'magento_', '/static/version'],
        'hubspot' => ['js.hs-scripts.com', 'hs-sites.com'],
        'joomla' => ['name="generator" content="joomla'],
        'nextjs' => ['/_next/static/', '__next_data__'],
    ];

    /**
     * Platforms an agency says it builds with (service copy). Word-bounded, case-insensitive.
     */
    private const OFFERS = [
        'wordpress' => '/\bwordpress\b/',
        'woocommerce' => '/\bwoo ?commerce\b/',
        'shopify' => '/\bshopify\b/',
        'laravel' => '/\blaravel\b/',
        'php' => '/\bphp\b/',
        'magento' => '/\b(?:magento|adobe commerce)\b/',
        'bigcommerce' => '/\bbigcommerce\b/',
        'webflow' => '/\bwebflow\b/',
        'drupal' => '/\bdrupal\b/',
        'react' => '/\breact(?:\.js|js)?\b/',
        'vue' => '/\bvue(?:\.js|js)?\b/',
        'nextjs' => '/\bnext\.?js\b/',
        'headless' => '/\bheadless (?:cms|commerce)\b/',
    ];

    /**
     * @param  list<string>  $cookieNames
     * @return list<string> e.g. ["built:wordpress", "offers:shopify", "flag:recruiter"]
     */
    public function detect(string $html, string $text, array $cookieNames = [], ?PageType $pageType = null): array
    {
        $lowerHtml = Str::lower($html);
        $lowerText = Str::lower($text);
        $found = [];

        foreach (self::BUILT_WITH as $stack => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lowerHtml, $needle)) {
                    $found[] = "built:{$stack}";

                    break;
                }
            }
        }

        $cookies = array_map('strtolower', $cookieNames);
        if (in_array('laravel_session', $cookies, true)) {
            $found[] = 'built:laravel';
        }

        // The site calls the company a recruiter (Known issue: neutral names like "Digital Waffle").
        // Only the home/about page counts, and "no recruitment agencies please" on a careers page never does.
        if (in_array($pageType, [PageType::Home, PageType::About], true)
            && preg_match('/\b(?:(?:we are|we\'re) an? (?:\w+ )?(?:recruitment|staffing|recruiting) (?:agency|firm|business|consultancy|company)|specialist (?:\w+ )?recruitment|recruitment consultancy|staffing agency|it recruitment agency|tech(?:nology)? recruitment agency|digital recruitment agency)\b/', $lowerText) === 1
            && preg_match('/\b(?:no|not accept\w*|do not work with) (?:\w+ ){0,3}(?:recruitment|recruiting|staffing) agenc/', $lowerText) !== 1) {
            $found[] = 'flag:recruiter';
        }

        // Website weaknesses a business would pay to fix (D127) — judged on the home page only.
        if ($pageType === PageType::Home) {
            if (preg_match('/<meta[^>]+name=["\']viewport["\']/', $lowerHtml) !== 1) {
                $found[] = 'audit:no_viewport';
            }

            if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'][^"\']{20,}/', $lowerHtml) !== 1) {
                $found[] = 'audit:no_meta_description';
            }

            preg_match_all('/(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?((?:19|20)\d{2})/u', $lowerHtml, $years);
            $copyrightYears = array_map('intval', $years[1]);

            if ($copyrightYears !== []) {
                if (max($copyrightYears) <= (int) date('Y') - 3) {
                    $found[] = 'audit:old_copyright';
                }
            }
        }

        foreach (self::OFFERS as $stack => $pattern) {
            if (preg_match($pattern, $lowerText) === 1) {
                $found[] = "offers:{$stack}";
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Stacks Webwayout can take overflow work on (offered or built with).
     */
    public const SERVED = ['wordpress', 'woocommerce', 'shopify', 'laravel', 'php', 'magento', 'webflow', 'bigcommerce', 'drupal'];

    /**
     * @param  list<string>  $stack  detect() output
     * @return list<string> served platforms the company offers to clients
     */
    public static function offeredServed(array $stack): array
    {
        return array_values(array_filter(
            self::SERVED,
            fn (string $s): bool => in_array("offers:{$s}", $stack, true),
        ));
    }
}
