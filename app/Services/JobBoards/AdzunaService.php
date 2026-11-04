<?php

declare(strict_types=1);

namespace App\Services\JobBoards;

use App\DTOs\JobBoards\JobAdData;
use App\DTOs\JobBoards\JobSearchPage;
use App\Enums\ExternalService;
use App\Enums\SignalSource;
use App\Services\Support\ExternalApiService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adzuna job search API (https://developer.adzuna.com/docs/search).
 * Auth: `app_id` + `app_key` query parameters. Includes recruiter-posted ads, so the
 * RecruiterDetector must run on every employer name (docs/04).
 */
final class AdzunaService extends ExternalApiService
{
    public const MAX_RESULTS_PER_PAGE = 50;

    protected function service(): ExternalService
    {
        return ExternalService::Adzuna;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.adzuna.base_url'))
            ->acceptJson()
            ->timeout((int) config('services.adzuna.timeout', 15));
    }

    /**
     * One page (1-based) of ads, newest first, no older than `$maxDaysOld`.
     */
    public function search(string $keywords, int $page = 1, int $maxDaysOld = 30, int $perPage = self::MAX_RESULTS_PER_PAGE): JobSearchPage
    {
        $country = (string) config('services.adzuna.country', 'gb');
        $page = max(1, $page);
        $endpoint = "/jobs/{$country}/search/{page}";

        $data = $this->json($this->get("/jobs/{$country}/search/{$page}", [
            'app_id' => $this->requireConfig('app_id'),
            'app_key' => $this->requireConfig('app_key'),
            'what' => $keywords,
            'results_per_page' => min(self::MAX_RESULTS_PER_PAGE, max(1, $perPage)),
            'max_days_old' => max(1, $maxDaysOld),
            'sort_by' => 'date',
        ], $endpoint), $endpoint);

        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        $ads = collect($results)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): ?JobAdData => $this->toJobAd($row))
            ->filter()
            ->values();

        return new JobSearchPage($ads, is_numeric($data['count'] ?? null) ? (int) $data['count'] : null);
    }

    /**
     * @param  array<mixed>  $row
     */
    private function toJobAd(array $row): ?JobAdData
    {
        $id = $row['id'] ?? null;
        $title = $this->text($row['title'] ?? null);
        $employer = $this->text(is_array($row['company'] ?? null) ? ($row['company']['display_name'] ?? null) : null);

        if ((! is_string($id) && ! is_int($id)) || (string) $id === '' || $title === null || $employer === null) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return new JobAdData(
            source: SignalSource::Adzuna,
            externalId: (string) $id,
            title: $title,
            employerName: $employer,
            employerExternalId: null,
            location: $this->text(is_array($row['location'] ?? null) ? ($row['location']['display_name'] ?? null) : null),
            description: $this->text($row['description'] ?? null),
            url: $this->text($row['redirect_url'] ?? null),
            externalUrl: null,
            postedAt: $this->date($row['created'] ?? null),
            raw: $row,
        );
    }

    /**
     * Adzuna wraps matched words in <strong> and may HTML-encode text.
     */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::squish(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
