<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\DTOs\Verification\EmailVerificationResult;
use App\Enums\EmailVerificationStatus;
use App\Enums\ExternalService;
use App\Exceptions\DailyCapReachedException;
use App\Exceptions\ExternalServiceException;
use App\Services\Support\ExternalApiService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * MillionVerifier single-email API (https://developer.millionverifier.com). Paid: 1 credit per call,
 * capped by `cap.millionverifier.per_day`. The key travels as the `api` query parameter (redacted in errors).
 */
final class MillionVerifierService extends ExternalApiService
{
    protected function service(): ExternalService
    {
        return ExternalService::MillionVerifier;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.millionverifier.base_url'))
            ->acceptJson()
            ->timeout((int) config('services.millionverifier.timeout', 20));
    }

    public function verify(string $email): EmailVerificationResult
    {
        $data = $this->json($this->get('/', [
            'api' => $this->requireConfig('key'),
            'email' => $email,
            'timeout' => min(60, max(2, (int) config('services.millionverifier.verify_timeout', 10))),
        ], '/api/v3'), '/api/v3');

        $this->throwOnAccountError($data);

        return new EmailVerificationResult(
            email: Str::lower(is_string($data['email'] ?? null) ? $data['email'] : $email),
            status: $this->status($data['result'] ?? null),
            subresult: is_string($data['subresult'] ?? null) && $data['subresult'] !== '' ? $data['subresult'] : null,
            quality: is_string($data['quality'] ?? null) && $data['quality'] !== '' ? $data['quality'] : null,
            creditsLeft: is_numeric($data['credits'] ?? null) ? (int) $data['credits'] : null,
            raw: $this->rawWithoutSecrets($data),
        );
    }

    /**
     * Remaining credit balance (shown on the Settings page). Free — not counted against the cap.
     */
    public function credits(): int
    {
        $data = $this->json($this->get('/credits', ['api' => $this->requireConfig('key')], '/api/v3/credits', 0.0), '/api/v3/credits');

        $this->throwOnAccountError($data);

        return is_numeric($data['credits'] ?? null) ? (int) $data['credits'] : 0;
    }

    /**
     * `result` → our enum. Anything unexpected is `error` (D67).
     */
    private function status(mixed $result): EmailVerificationStatus
    {
        return match (is_string($result) ? Str::lower($result) : null) {
            'ok' => EmailVerificationStatus::Ok,
            'catch_all' => EmailVerificationStatus::CatchAll,
            'unknown' => EmailVerificationStatus::Unknown,
            'invalid' => EmailVerificationStatus::Invalid,
            'disposable' => EmailVerificationStatus::Disposable,
            default => EmailVerificationStatus::Error,
        };
    }

    /**
     * Account problems arrive as HTTP 200 with an `error` text. Out of credits waits like a spent daily cap
     * (no lead should fail because the balance ran out); anything else (bad key…) is a service failure.
     *
     * @param  array<mixed>  $data
     */
    private function throwOnAccountError(array $data): void
    {
        $error = is_string($data['error'] ?? null) ? trim($data['error']) : '';

        if ($error === '') {
            return;
        }

        if (str_contains(Str::lower($error), 'credit')) {
            throw new DailyCapReachedException($this->service(), 0, 0, $this->limiter->resumeAt());
        }

        throw new ExternalServiceException($this->service(), "account error: {$error}");
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function rawWithoutSecrets(array $data): array
    {
        $raw = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, ['email', 'quality', 'result', 'resultcode', 'subresult', 'free', 'role', 'didyoumean', 'executiontime'], true)) {
                $raw[$key] = $value;
            }
        }

        return $raw;
    }
}
