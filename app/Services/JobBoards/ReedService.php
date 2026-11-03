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
 * Reed.co.uk job search API (https://www.reed.co.uk/developers/jobseeker).
 * Auth: HTTP Basic, API key as username, empty password.
 */
final class ReedService extends ExternalApiService
{
    public const MAX_RESULTS_PER_PAGE = 100;

    protected function service(): ExternalService
    {
        return ExternalService::Reed;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.reed.base_url'))
            ->withBasicAuth($this->requireConfig('key'), '')
            ->acceptJson()
            ->timeout((int) config('services.reed.timeout', 15));
    }

    /**
     * One page of ads posted by direct employers (recruiters excluded at source).
     */
    public function search(string $keywords, int $skip = 0, int $take = self::MAX_RESULTS_PER_PAGE): JobSearchPage
    {
        $data = $this->json($this->get('/search', [
            'keywords' => $keywords,
            'postedByDirectEmployer' => 'true',
            'resultsToSkip' => max(0, $skip),
            'resultsToTake' => min(self::MAX_RESULTS_PER_PAGE, max(1, $take)),
        ], '/search'), '/search');

        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        $ads = collect($results)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): ?JobAdData => $this->toJobAd($row))
            ->filter()
            ->values();

        return new JobSearchPage(
            $ads,
            is_numeric($data['totalResults'] ?? null) ? (int) $data['totalResults'] : null,
        );
    }

    /**
     * Full ad (complete description, external URL). Reed returns a blank job when the id is unknown → null.
     */
    public function getJob(int $jobId): ?JobAdData
    {
        return $this->toJobAd($this->json($this->get("/jobs/{$jobId}", [], '/jobs/{id}'), '/jobs/{id}'));
    }

    /**
     * @param  array<mixed>  $row
     */
    private function toJobAd(array $row): ?JobAdData
    {
        $jobId = $row['jobId'] ?? null;
        $title = $this->text($row['jobTitle'] ?? null);
        $employer = $this->text($row['employerName'] ?? null);

        if (! is_numeric($jobId) || (int) $jobId <= 0 || $title === null || $employer === null) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return new JobAdData(
            source: SignalSource::Reed,
            externalId: (string) (int) $jobId,
            title: $title,
            employerName: $employer,
            employerExternalId: is_numeric($row['employerId'] ?? null) ? (string) $row['employerId'] : null,
            location: $this->text($row['locationName'] ?? null),
            description: $this->plainText($row['jobDescription'] ?? null),
            url: $this->text($row['jobUrl'] ?? null),
            externalUrl: $this->text($row['externalUrl'] ?? null),
            postedAt: $this->date($row['datePosted'] ?? $row['date'] ?? null),
            raw: $row,
        );
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    private function plainText(mixed $html): ?string
    {
        if (! is_string($html)) {
            return null;
        }

        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $html)), ENT_QUOTES | ENT_HTML5);

        return $this->text($text);
    }

    /**
     * Reed dates are UK format, e.g. "28/10/2026".
     */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = preg_match('#^\d{2}/\d{2}/\d{4}$#', $value) === 1
                ? CarbonImmutable::createFromFormat('d/m/Y', $value, 'Europe/London')
                : CarbonImmutable::parse($value, 'Europe/London');
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable ? $date->startOfDay() : null;
    }
}
