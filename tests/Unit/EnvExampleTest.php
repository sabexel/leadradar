<?php

declare(strict_types=1);

/**
 * The repo is public: .env.example must list every API key, all empty.
 */
function envExample(): array
{
    $values = [];

    foreach (file(dirname(__DIR__, 2).'/.env.example', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) === 1) {
            $values[$m[1]] = trim($m[2]);
        }
    }

    return $values;
}

it('lists every external API key with an empty value', function (string $key) {
    expect(envExample())->toHaveKey($key)
        ->and(envExample()[$key])->toBe('');
})->with([
    'COMPANIES_HOUSE_API_KEY',
    'REED_API_KEY',
    'ADZUNA_APP_ID',
    'ADZUNA_APP_KEY',
    'MILLIONVERIFIER_API_KEY',
    'BRAVE_SEARCH_API_KEY',
]);

it('keeps public registration off by default', function () {
    expect(envExample()['REGISTRATION_ENABLED'] ?? null)->toBe('false');
});

it('uses the database queue and cache drivers', function () {
    expect(envExample())
        ->QUEUE_CONNECTION->toBe('database')
        ->CACHE_STORE->toBe('database');
});
