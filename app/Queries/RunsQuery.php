<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ExternalService;
use App\Models\ApiUsage;
use App\Models\ScrapeRun;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs page (docs/07 §5): discovery runs, API usage per service per day, failed queue jobs. Local queries only.
 */
final class RunsQuery
{
    public const USAGE_DAYS = 14;

    /**
     * @return LengthAwarePaginator<int, ScrapeRun>
     */
    public function runs(int $perPage = 20): LengthAwarePaginator
    {
        return ScrapeRun::query()->latest('started_at')->latest('id')->paginate($perPage)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(ScrapeRun $run): array
    {
        return [
            'id' => $run->id,
            'startedAt' => $run->started_at->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'durationSeconds' => $run->finished_at === null ? null : (int) $run->started_at->diffInSeconds($run->finished_at),
            'trigger' => $run->trigger->value,
            'status' => $run->status->value,
            'signalsFound' => $run->signals_found,
            'companiesCreated' => $run->companies_created,
            'qualified' => $run->qualified_count,
            'rejected' => $run->rejected_count,
        ];
    }

    /**
     * Last 14 London business days, newest first; one row per day with calls / cost units / errors per service.
     *
     * @return array{services: list<array{value: string, label: string}>, days: list<array{date: string, services: array<string, array{calls: int, cost: float, errors: int}>}>}
     */
    public function apiUsage(): array
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $first = $today->subDays(self::USAGE_DAYS - 1);

        $rows = ApiUsage::query()->toBase()
            ->where('date', '>=', $first->toDateString())
            ->selectRaw('date, service, count(*) as calls, sum(cost_units) as cost, sum(case when status_code is null or status_code >= 400 then 1 else 0 end) as errors')
            ->groupBy('date', 'service')
            ->get();

        $empty = array_fill_keys(array_map(fn (ExternalService $s): string => $s->value, ExternalService::cases()), ['calls' => 0, 'cost' => 0.0, 'errors' => 0]);
        $days = [];

        for ($day = $today; $day->greaterThanOrEqualTo($first); $day = $day->subDay()) {
            $days[$day->toDateString()] = $empty;
        }

        foreach ($rows as $row) {
            $date = substr((string) $row->date, 0, 10);

            if (isset($days[$date], $days[$date][(string) $row->service])) {
                $days[$date][(string) $row->service] = [
                    'calls' => (int) $row->calls,
                    'cost' => round((float) $row->cost, 2),
                    'errors' => (int) $row->errors,
                ];
            }
        }

        return [
            'services' => array_map(fn (ExternalService $s): array => ['value' => $s->value, 'label' => $s->label()], ExternalService::cases()),
            'days' => array_map(fn (string $date, array $services): array => ['date' => $date, 'services' => $services], array_keys($days), array_values($days)),
        ];
    }

    /**
     * Newest 50 failed queue jobs: job class, queue, the exception's first line, when.
     *
     * @return list<array{uuid: string, job: string, queue: string, error: string, failedAt: string}>
     */
    public function failedJobs(): array
    {
        return array_values(DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(function (object $row): array {
                $payload = json_decode((string) $row->payload, true);
                $job = is_array($payload) && is_string($payload['displayName'] ?? null) ? class_basename($payload['displayName']) : 'Unknown job';

                return [
                    'uuid' => (string) $row->uuid,
                    'job' => $job,
                    'queue' => (string) $row->queue,
                    'error' => Str::limit(strtok((string) $row->exception, "\n") ?: '', 300),
                    'failedAt' => CarbonImmutable::parse((string) $row->failed_at, 'UTC')->toIso8601String(),
                ];
            })
            ->all());
    }

    public function failedJobCount(): int
    {
        return DB::table('failed_jobs')->count();
    }
}
