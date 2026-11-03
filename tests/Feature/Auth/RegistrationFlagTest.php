<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['leadradar.registration_enabled' => false]);
});

it('returns 404 for the registration screen when registration is disabled', function () {
    $this->get('/register')->assertNotFound();
});

it('returns 404 for registration submissions when registration is disabled', function () {
    $this->post('/register', [
        'name' => 'Jane Example',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

it('shares canRegister with the frontend', function (bool $enabled) {
    config(['leadradar.registration_enabled' => $enabled]);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('canRegister', $enabled));
})->with([true, false]);
