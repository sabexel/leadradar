<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\ExternalService;
use App\Exceptions\DailyCapReachedException;
use App\Exceptions\ExternalServiceException;
use App\Exceptions\RateLimitedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Shared plumbing for every external API service (docs/04):
 * check caps → call → log ApiUsage → map errors to typed exceptions.
 * Subclasses own their endpoints and return DTOs.
 */
abstract class ExternalApiService
{
    public function __construct(protected readonly ApiUsageLimiter $limiter) {}

    abstract protected function service(): ExternalService;

    /**
     * Base request: base URL, auth, timeout. Retries are added by `get()`.
     */
    abstract protected function client(): PendingRequest;

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws RateLimitedException
     * @throws DailyCapReachedException
     * @throws ExternalServiceException
     */
    protected function get(string $path, array $query, string $endpoint, float $cost = 1.0): Response
    {
        $this->limiter->ensureAvailable($this->service(), $cost);

        $started = hrtime(true);

        try {
            $response = $this->client()
                ->retry(2, 500, fn (Throwable $e): bool => $this->shouldRetry($e), throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            $this->limiter->record($this->service(), $endpoint, null, $this->elapsedMs($started), $cost);

            throw new ExternalServiceException($this->service(), 'connection failed: '.$this->redact($e->getMessage()));
        }

        $this->limiter->record($this->service(), $endpoint, $response->status(), $this->elapsedMs($started), $cost);

        if ($response->status() === 429) {
            throw new RateLimitedException($this->service(), $this->retryAfter($response), 'provider returned 429');
        }

        if ($response->failed()) {
            throw new ExternalServiceException($this->service(), "{$endpoint} returned HTTP {$response->status()}", $response->status());
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    protected function json(Response $response, string $endpoint): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new ExternalServiceException($this->service(), "{$endpoint} returned malformed JSON", $response->status());
        }

        return $data;
    }

    protected function requireConfig(string $key): string
    {
        $value = config("services.{$this->service()->value}.{$key}");

        if (! is_string($value) || $value === '') {
            throw new ExternalServiceException($this->service(), "services.{$this->service()->value}.{$key} is not configured");
        }

        return $value;
    }

    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        // Retry server errors and 429 only — never other 4xx (docs/04).
        return $e instanceof RequestException
            && ($e->response->serverError() || $e->response->status() === 429);
    }

    /**
     * Strip credentials from text that may contain a request URL (some APIs take keys as query params).
     */
    private function redact(string $text): string
    {
        return (string) preg_replace('/([?&](?:app_id|app_key|api|api_key|key|token)=)[^&\s"\']+/i', '$1[redacted]', $text);
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : 60;
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
