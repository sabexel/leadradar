<?php

declare(strict_types=1);

use App\Enums\EmailVerificationStatus;
use App\Enums\ExternalService;
use App\Exceptions\DailyCapReachedException;
use App\Exceptions\ExternalServiceException;
use App\Models\ApiUsage;
use App\Models\Setting;
use App\Services\Verification\MillionVerifierService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['services.millionverifier.key' => 'test-mv-key']));

it('verifies one address with the key, email and a 10 s timeout', function () {
    Http::fake(['api.millionverifier.com/api/v3/*' => Http::response(jsonFixture('millionverifier/ok.json'))]);

    $result = app(MillionVerifierService::class)->verify('jane.example@example-digital.test');

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.millionverifier.com/api/v3/?')
        && $request['api'] === 'test-mv-key'
        && $request['email'] === 'jane.example@example-digital.test'
        && (int) $request['timeout'] === 10);

    expect($result->status)->toBe(EmailVerificationStatus::Ok)
        ->and($result->quality)->toBe('good')
        ->and($result->creditsLeft)->toBe(3454)
        ->and($result->raw)->toHaveKeys(['result', 'resultcode', 'subresult'])
        ->and($result->raw)->not->toHaveKeys(['credits', 'error', 'livemode']);
});

it('maps every result value', function (string $fixture, EmailVerificationStatus $status) {
    Http::fake(['*' => Http::response(jsonFixture("millionverifier/{$fixture}.json"))]);

    expect(app(MillionVerifierService::class)->verify('jane.example@example-digital.test')->status)->toBe($status);
})->with([
    ['ok', EmailVerificationStatus::Ok],
    ['catch_all', EmailVerificationStatus::CatchAll],
    ['unknown', EmailVerificationStatus::Unknown],
    ['invalid', EmailVerificationStatus::Invalid],
    ['disposable', EmailVerificationStatus::Disposable],
    ['error', EmailVerificationStatus::Error],
]);

it('charges one credit per verification and logs it', function () {
    Http::fake(['*' => Http::response(jsonFixture('millionverifier/ok.json'))]);

    app(MillionVerifierService::class)->verify('jane.example@example-digital.test');

    expect(ApiUsage::sole()->service)->toBe(ExternalService::MillionVerifier)
        ->and(ApiUsage::sole()->cost_units)->toBe('1.00');
});

it('fails loudly on a bad API key', function () {
    Http::fake(['*' => Http::response(jsonFixture('millionverifier/bad-key.json'))]);

    app(MillionVerifierService::class)->verify('jane.example@example-digital.test');
})->throws(ExternalServiceException::class, 'Invalid API key');

it('treats an empty balance like a spent daily cap', function () {
    Http::fake(['*' => Http::response(jsonFixture('millionverifier/no-credits.json'))]);

    app(MillionVerifierService::class)->verify('jane.example@example-digital.test');
})->throws(DailyCapReachedException::class);

it('stops before calling once today\'s cap is used', function () {
    Setting::put('cap.millionverifier.per_day', 0);
    Http::fake();

    expect(fn () => app(MillionVerifierService::class)->verify('jane.example@example-digital.test'))->toThrow(DailyCapReachedException::class);

    Http::assertNothingSent();
});

it('reads the credit balance for free', function () {
    Http::fake(['api.millionverifier.com/api/v3/credits*' => Http::response(jsonFixture('millionverifier/credits.json'))]);

    expect(app(MillionVerifierService::class)->credits())->toBe(3454)
        ->and(ApiUsage::sole()->cost_units)->toBe('0.00');
});
