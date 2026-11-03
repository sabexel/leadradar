<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Decoded JSON fixture from tests/Fixtures, e.g. jsonFixture('reed/search.json').
 *
 * @return array<mixed>
 */
function jsonFixture(string $path): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$path), true, flags: JSON_THROW_ON_ERROR);
}
