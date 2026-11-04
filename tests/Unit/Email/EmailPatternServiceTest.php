<?php

declare(strict_types=1);

use App\Models\Person;
use App\Services\Email\EmailPatternService;

beforeEach(fn () => $this->patterns = new EmailPatternService);

function person(string $first, string $last, ?string $preferred = null): Person
{
    return new Person(['first_name' => $first, 'last_name' => $last, 'preferred_name' => $preferred]);
}

it('generates candidates in the documented order', function () {
    expect($this->patterns->candidates(person('Jane', 'Example'), 'example-digital.test'))->toBe([
        'jane@example-digital.test',
        'jane.example@example-digital.test',
        'janeexample@example-digital.test',
        'j.example@example-digital.test',
        'jexample@example-digital.test',
        'jane_example@example-digital.test',
        'example@example-digital.test',
    ]);
});

it('tries a pattern confirmed for a colleague first, keeping the rest in order', function () {
    expect($this->patterns->candidates(person('Jane', 'Example'), 'example-digital.test', 'f.last'))->toBe([
        'j.example@example-digital.test',
        'jane@example-digital.test',
        'jane.example@example-digital.test',
        'janeexample@example-digital.test',
        'jexample@example-digital.test',
        'jane_example@example-digital.test',
        'example@example-digital.test',
    ]);
});

it('ignores an unknown preferred pattern', function () {
    expect($this->patterns->candidates(person('Jane', 'Example'), 'example-digital.test', 'nonsense')[0])->toBe('jane@example-digital.test');
});

it('normalises names to plain lowercase letters', function () {
    expect($this->patterns->candidates(person('José', "O'Example-Smith"), 'www.Example.test')[1])->toBe('jose.oexamplesmith@example.test');
});

it('uses a hand-set preferred name', function () {
    expect($this->patterns->candidates(person('Joshua', 'Sample', 'Josh'), 'example.test')[0])->toBe('josh@example.test');
});

it('returns nothing without both a first and a last name', function () {
    expect($this->patterns->candidates(person('', 'Example'), 'example.test'))->toBe([])
        ->and($this->patterns->candidates(person('Jane', '—'), 'example.test'))->toBe([]);
});

it('recognises which pattern an address follows', function (string $email, ?string $pattern) {
    expect($this->patterns->patternOf(person('John', 'Sample'), $email))->toBe($pattern);
})->with([
    ['john@x.test', 'first'],
    ['john.sample@x.test', 'first.last'],
    ['JohnSample@x.test', 'firstlast'],
    ['j.sample@x.test', 'f.last'],
    ['jsample@x.test', 'flast'],
    ['john_sample@x.test', 'first_last'],
    ['sample@x.test', 'last'],
    ['hello@x.test', null],
    ['not-an-email', null],
]);
