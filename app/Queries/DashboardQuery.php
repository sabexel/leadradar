<?php

declare(strict_types=1);

namespace App\Queries;

use App\DTOs\Dashboard\DateRange;
use App\Enums\EmailKind;
use App\Enums\EmailVerificationStatus;
use App\Enums\ExternalService;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Models\ApiUsage;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Services\Support\ApiUsageLimiter;
use App\Services\Support\DailyTarget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only numbers for the dashboard (docs/07 §1). Local queries only — no external calls.
 *
 * Counting rules (D85):
 * - "Qualified" (cards, per-day chart, average score) counts by `qualified_at` — the same clock as the daily target.
 * - Rejected / In progress / Errors, the funnel, reject reasons and the score histogram describe the cohort of
 *   companies *discovered* in the range, by their current status.
 * - Days are London business days (D20).
 */
final class DashboardQuery
{
    public function __construct(
        private readonly ApiUsageLimiter $limiter,
        private readonly DailyTarget $target,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(DateRange $range): array
    {
        return [
            'range' => $range->toArray(),
            'stats' => $this->stats($range),
            'qualifiedPerDay' => $this->qualifiedPerDay(),
            'funnel' => $this->funnel($range),
            'rejectReasons' => $this->rejectReasons($range),
            'scoreDistribution' => $this->scoreDistribution($range),
            'sources' => $this->sources($range),
            'runs' => $this->runs(),
            'apiUsage' => $this->apiUsage(),
            'credits' => $this->credits(),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function stats(DateRange $range): array
    {
        $cohort = $this->cohort($range);
        $byStatus = (clone $cohort)->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $n): int => (int) $n);

        $qualified = Company::query()
            ->where('status', LeadStatus::Qualified)
            ->whereBetween('qualified_at', [$range->startUtc(), $range->endUtc()->subSecond()]);

        $contacted = Company::query()->whereBetween('contacted_at', [$range->startUtc(), $range->endUtc()->subSecond()]);

        $average = (clone $qualified)->avg('score');

        return [
            'scraped' => Signal::query()->whereBetween('created_at', [$range->startUtc(), $range->endUtc()->subSecond()])->count(),
            'discovered' => (int) $byStatus->sum(),
            'qualified' => $qualified->count(),
            'rejected' => $byStatus->get(LeadStatus::Rejected->value, 0),
            'inProgress' => $byStatus->get(LeadStatus::Discovered->value, 0) + $byStatus->get(LeadStatus::Enriching->value, 0),
            'errors' => $byStatus->get(LeadStatus::Error->value, 0),
            'averageScore' => $average === null ? null : round((float) $average, 1),
            'verifiedFounderEmails' => EmailAddress::query()
                ->where('kind', EmailKind::Person)
                ->where('verification_status', EmailVerificationStatus::Ok)
                ->whereBetween('verified_at', [$range->startUtc(), $range->endUtc()->subSecond()])
                ->count(),
            'contacted' => (clone $contacted)->count(),
            'replied' => (clone $contacted)->whereIn('outreach_status', [OutreachStatus::Replied, OutreachStatus::Client])->count(),
        ];
    }

    /**
     * Last 30 London days, oldest first, with the daily target for the reference line.
     *
     * @return array{target: int, days: list<array{date: string, qualified: int}>}
     */
    private function qualifiedPerDay(): array
    {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $first = $today->subDays(29);

        $days = [];
        for ($day = $first; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $days[$day->toDateString()] = 0;
        }

        Company::query()
            ->where('status', LeadStatus::Qualified)
            ->where('qualified_at', '>=', $first->utc())
            ->pluck('qualified_at')
            ->each(function (mixed $at) use (&$days, $timezone): void {
                if ($at instanceof CarbonInterface) {
                    $key = CarbonImmutable::instance($at)->setTimezone($timezone)->toDateString();

                    if (isset($days[$key])) {
                        $days[$key]++;
                    }
                }
            });

        return [
            'target' => $this->target->target(),
            'days' => array_map(
                fn (string $date, int $count): array => ['date' => $date, 'qualified' => $count],
                array_keys($days),
                array_values($days),
            ),
        ];
    }

    /**
     * @return list<array{stage: string, label: string, count: int}>
     */
    private function funnel(DateRange $range): array
    {
        $cohort = $this->cohort($range);

        return [
            ['stage' => 'discovered', 'label' => 'Discovered', 'count' => (clone $cohort)->count()],
            ['stage' => 'registry', 'label' => 'Registry ok', 'count' => (clone $cohort)->whereNotNull('registration_number')->count()],
            ['stage' => 'website', 'label' => 'Website found', 'count' => (clone $cohort)->whereNotNull('domain')->count()],
            ['stage' => 'email', 'label' => 'Email verified', 'count' => (clone $cohort)->whereHas(
                'emailAddresses',
                fn (Builder $q) => $q->where('kind', EmailKind::Person)->where('verification_status', EmailVerificationStatus::Ok),
            )->count()],
            ['stage' => 'qualified', 'label' => 'Qualified', 'count' => (clone $cohort)->where('status', LeadStatus::Qualified)->count()],
        ];
    }

    /**
     * @return list<array{reason: string, label: string, count: int}>
     */
    private function rejectReasons(DateRange $range): array
    {
        $counts = $this->cohort($range)->toBase()
            ->where('status', LeadStatus::Rejected->value)
            ->whereNotNull('reject_reason')
            ->selectRaw('reject_reason, count(*) as total')
            ->groupBy('reject_reason')
            ->pluck('total', 'reject_reason');

        $rows = [];
        foreach ($counts as $reason => $total) {
            $rows[] = [
                'reason' => (string) $reason,
                'label' => RejectReason::tryFrom((string) $reason)?->label() ?? (string) $reason,
                'count' => (int) $total,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * Scored companies in the cohort, bucketed by whole point: bucket 6 = scores 6.0–6.9, bucket 10 = 10.0.
     *
     * @return list<array{bucket: int, count: int}>
     */
    private function scoreDistribution(DateRange $range): array
    {
        $buckets = array_fill(0, 11, 0);

        $this->cohort($range)->whereNotNull('score')->pluck('score')->each(function (mixed $score) use (&$buckets): void {
            $buckets[max(0, min(10, (int) floor((float) $score)))]++;
        });

        return array_map(fn (int $bucket, int $count): array => ['bucket' => $bucket, 'count' => $count], array_keys($buckets), $buckets);
    }

    /**
     * Signals ingested in the range, per source.
     *
     * @return list<array{source: string, label: string, count: int}>
     */
    private function sources(DateRange $range): array
    {
        $counts = Signal::query()->toBase()
            ->whereBetween('created_at', [$range->startUtc(), $range->endUtc()->subSecond()])
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $rows = [];
        foreach ($counts as $source => $total) {
            $rows[] = [
                'source' => (string) $source,
                'label' => SignalSource::tryFrom((string) $source)?->label() ?? (string) $source,
                'count' => (int) $total,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runs(): array
    {
        return array_values(ScrapeRun::query()
            ->latest('started_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ScrapeRun $run): array => [
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
            ])
            ->all());
    }

    /**
     * Today's (London) spend per service against its daily cap.
     *
     * @return list<array{service: string, label: string, calls: int, used: float, cap: float|null, errors: int}>
     */
    private function apiUsage(): array
    {
        $today = $this->limiter->businessDate();

        $totals = ApiUsage::query()->toBase()
            ->where('date', $today)
            ->selectRaw('service, count(*) as calls, sum(case when status_code is null or status_code >= 400 then 1 else 0 end) as errors')
            ->groupBy('service')
            ->get()
            ->keyBy('service');

        return array_map(function (ExternalService $service) use ($totals): array {
            $row = $totals->get($service->value);

            return [
                'service' => $service->value,
                'label' => $service->label(),
                'calls' => (int) ($row->calls ?? 0),
                'used' => $this->limiter->usedToday($service),
                'cap' => $this->limiter->dailyCap($service),
                'errors' => (int) ($row->errors ?? 0),
            ];
        }, ExternalService::cases());
    }

    /**
     * Last balance MillionVerifier reported with a verification (stored by ApplyVerificationResultAction, D86).
     *
     * @return array{credits: int, at: string|null}|null
     */
    private function credits(): ?array
    {
        $value = Setting::get('millionverifier.credits');

        if (! is_array($value) || ! is_numeric($value['credits'] ?? null)) {
            return null;
        }

        return ['credits' => (int) $value['credits'], 'at' => is_string($value['at'] ?? null) ? $value['at'] : null];
    }

    /**
     * @return Builder<Company>
     */
    private function cohort(DateRange $range): Builder
    {
        return Company::query()->whereBetween('created_at', [$range->startUtc(), $range->endUtc()->subSecond()]);
    }

    private function timezone(): string
    {
        return (string) config('leadradar.timezone', 'Europe/London');
    }
}
