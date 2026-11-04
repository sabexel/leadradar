<?php

declare(strict_types=1);

use App\Services\Crawler\RobotsRules;
use App\Services\Crawler\RobotsTxtGuard;

beforeEach(fn () => $this->guard = new RobotsTxtGuard);

function robotsFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/robots/{$name}");
}

it('applies the * group with longest-match precedence, wildcards and $ anchors', function (string $path, bool $allowed) {
    expect($this->guard->parse(robotsFixture('allow.txt'))->allows($path))->toBe($allowed);
})->with([
    ['/', true],
    ['/contact', true],
    ['/admin', false],
    ['/admin/settings', false],
    ['/admin/public', true],     // longer Allow beats shorter Disallow
    ['/data/feed.json', false],  // "/*.json$"
    ['/data/feed.json?x', true], // "$" anchors the end
]);

it('uses our own group over the * group and reads Crawl-delay', function () {
    $rules = $this->guard->parse(robotsFixture('leadradar-specific.txt'));

    expect($rules->allows('/'))->toBeTrue()          // * would block everything — our group wins
        ->and($rules->allows('/careers'))->toBeFalse()
        ->and($rules->allows('/private'))->toBeTrue() // Googlebot-only rule
        ->and($rules->crawlDelay)->toBe(5);
});

it('blocks everything when the * group disallows /', function () {
    expect($this->guard->parse(robotsFixture('block-all.txt'))->allows('/'))->toBeFalse();
});

it('allows everything for an empty file or an empty Disallow', function () {
    expect($this->guard->parse('')->allows('/anything'))->toBeTrue()
        ->and($this->guard->parse("User-agent: *\nDisallow:\n")->allows('/anything'))->toBeTrue();
});

it('maps HTTP outcomes per RFC 9309', function (?int $status, bool $homeAllowed) {
    expect($this->guard->rulesForResponse($status, robotsFixture('block-all.txt'))->allows('/'))->toBe($homeAllowed);
})->with([
    'parsed on 200' => [200, false],
    'allow all on 404' => [404, true],
    'allow all on 403' => [403, true],
    'disallow all on 500' => [500, false],
    'disallow all when unreachable' => [null, false],
]);

it('round-trips rules through the cache and enforces the per-domain gap', function () {
    $this->guard->remember('example-digital.test', new RobotsRules([['allow' => false, 'pattern' => '/x']], 4));

    expect($this->guard->cached('example-digital.test')?->allows('/x'))->toBeFalse()
        ->and($this->guard->cached('other.test'))->toBeNull()
        ->and($this->guard->secondsUntilAllowed('example-digital.test'))->toBe(0);

    $this->guard->markRequested('example-digital.test');

    expect($this->guard->secondsUntilAllowed('example-digital.test'))->toBeGreaterThanOrEqual(3)
        ->and($this->guard->secondsUntilAllowed('example-digital.test'))->toBeLessThanOrEqual(4); // Crawl-delay 4 > 2 s minimum

    $this->travel(5)->seconds();

    expect($this->guard->secondsUntilAllowed('example-digital.test'))->toBe(0);
});
