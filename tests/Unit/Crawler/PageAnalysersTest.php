<?php

declare(strict_types=1);

use App\DTOs\Classification\DetectedSignal;
use App\DTOs\Crawl\ExtractedEmail;
use App\Enums\PageType;
use App\Services\Crawler\CrawlPlanner;
use App\Services\Crawler\EmailExtractor;
use App\Services\Crawler\LaravelSignalDetector;
use Symfony\Component\DomCrawler\Crawler;

function htmlFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/html/{$name}");
}

/**
 * Visible text the way the crawler sees it (scripts and styles removed).
 */
function visibleText(string $html): string
{
    $dom = new Crawler($html);
    $dom->filter('script, style')->each(function (Crawler $node): void {
        foreach ($node as $el) {
            $el->parentNode?->removeChild($el);
        }
    });

    return $dom->filter('body')->text('');
}

/**
 * @param  list<ExtractedEmail>  $emails
 * @return array<string, string>
 */
function emailKinds(array $emails): array
{
    return collect($emails)->mapWithKeys(fn (ExtractedEmail $e) => [$e->email => $e->kind->value])->all();
}

// --- EmailExtractor ------------------------------------------------------------------------

it('extracts mailto, plain and obfuscated emails on the company domain only', function () {
    $html = htmlFixture('home.html');

    $emails = (new EmailExtractor)->extract($html, visibleText($html), 'example-digital.test', 'https://www.example-digital.test/');

    expect(emailKinds($emails))->toBe([
        'hello@example-digital.test' => 'contact',  // mailto with ?subject stripped
        'jane@example-digital.test' => 'other',     // "jane [at] example-digital [dot] test"
    ])
        ->and($emails[0]->sourceUrl)->toBe('https://www.example-digital.test/');
    // tracking@… lives in a <script> (not visible text); the gmail address is third-party.
});

it('classifies contact and careers inboxes, drops no-reply, keeps subdomain addresses', function () {
    $html = htmlFixture('contact.html');

    $emails = (new EmailExtractor)->extract($html, visibleText($html), 'example-digital.test', 'https://www.example-digital.test/contact');

    expect(emailKinds($emails))->toBe([
        'hello@example-digital.test' => 'contact',     // upper-case mailto, deduplicated
        'john.sample@example-digital.test' => 'other',  // a person — matched to a founder later
        'careers@example-digital.test' => 'careers',
        'accounts@mail.example-digital.test' => 'other',
    ]);
});

it('accepts the domain or its subdomains, never look-alikes', function (string $email, bool $ok) {
    expect((new EmailExtractor)->isCompanyEmail($email, 'www.example-digital.test'))->toBe($ok);
})->with([
    ['a@example-digital.test', true],
    ['a@mail.example-digital.test', true],
    ['a@notexample-digital.test', false],
    ['a@example-digital.test.evil.test', false],
    ['not-an-email', false],
]);

// --- LaravelSignalDetector -----------------------------------------------------------------

/**
 * @param  list<DetectedSignal>  $signals
 * @return array<string, string>
 */
function laravelTypes(array $signals): array
{
    return collect($signals)->mapWithKeys(fn (DetectedSignal $s) => [$s->type => $s->weight->value])->all();
}

it('detects a partner link, Livewire and the session cookie on the home page', function () {
    $html = htmlFixture('home.html');

    $signals = (new LaravelSignalDetector)->detect($html, visibleText($html), PageType::Home, 'https://www.example-digital.test/', ['laravel_session', 'other']);

    expect(laravelTypes($signals))->toBe([
        'laravel_mention' => 'medium',  // "Laravel" on a home page (not services/careers)
        'laravel_partner' => 'strong',
        'runs_laravel' => 'medium',
        'livewire' => 'medium',
    ]);
});

it('treats "Laravel" on a services or careers page as strong', function (string $file, PageType $type, string $url) {
    $html = htmlFixture($file);

    expect(laravelTypes((new LaravelSignalDetector)->detect($html, visibleText($html), $type, $url)))
        ->toBe(['laravel_mention' => 'strong']);
})->with([
    ['services.html', PageType::Services, 'https://www.example-digital.test/services/laravel'],
    ['careers.html', PageType::Careers, 'https://www.example-digital.test/careers'],
]);

it('treats "Laravel" in a list of frameworks as weak', function () {
    $html = htmlFixture('about.html');

    expect(laravelTypes((new LaravelSignalDetector)->detect($html, visibleText($html), PageType::About, 'https://www.example-digital.test/about')))
        ->toBe(['framework_list' => 'weak']);
});

it('detects an Inertia root element', function () {
    $html = htmlFixture('no-links-home.html');

    expect(laravelTypes((new LaravelSignalDetector)->detect($html, '', PageType::Home, 'https://www.example-digital.test/')))
        ->toBe(['inertia' => 'medium']);
});

// --- CrawlPlanner (pure parts) -------------------------------------------------------------

it('keeps same-domain candidate links, best first, without queries, files or socials', function () {
    $html = htmlFixture('home.html');
    $links = (new Crawler($html, 'https://www.example-digital.test/'))->filter('a[href]')
        ->each(fn (Crawler $a) => ['href' => (string) $a->attr('href'), 'text' => $a->text('')]);

    expect((new CrawlPlanner)->candidateLinks($links, 'https://www.example-digital.test/', 'example-digital.test'))->toBe([
        'https://www.example-digital.test/contact',
        'https://www.example-digital.test/about/',
        'https://www.example-digital.test/careers',
        'https://www.example-digital.test/services/laravel',
    ]);
});

it('classifies page types from the URL and link text', function (string $url, string $text, PageType $type) {
    expect((new CrawlPlanner)->pageTypeOf($url, $text))->toBe($type);
})->with([
    ['https://x.test/', '', PageType::Home],
    ['https://x.test/contact-us', '', PageType::Contact],
    ['https://x.test/meet-the-team', '', PageType::Team],
    ['https://x.test/who-we-are', 'About us', PageType::About],
    ['https://x.test/jobs/laravel-dev', '', PageType::Careers],
    ['https://x.test/case-studies/one', '', PageType::Services],
    ['https://x.test/blog/post', 'Read more', PageType::Other],
]);
