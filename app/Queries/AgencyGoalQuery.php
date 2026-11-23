<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ExternalService;
use App\Enums\LeadProduct;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Models\ApiUsage;
use App\Models\Company;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * The owner's goal (2026-11-23): N qualified agencies a day (D126). Local queries only; London days.
 */
final class AgencyGoalQuery
{
    /**
     * @return array{goal: int, today: int, inProgress: int, days: list<array{date: string, qualified: int}>, creditsPerQualified: float|null, emailFoundRate: float|null, finished7d: int, byCountry: list<array{country: string, count: int}>}
     */
    public function get(): array
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $weekStart = $today->subDays(6);

        $agencies = fn () => Company::query()->where('lead_product', LeadProduct::Agency);

        $days = [];
        for ($day = $weekStart; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'qualified' => $agencies()->where('status', LeadStatus::Qualified)
                    ->where('qualified_at', '>=', $day->utc())->where('qualified_at', '<', $day->addDay()->utc())->count(),
            ];
        }

        $qualified7d = array_sum(array_column($days, 'qualified'));
        $credits7d = (float) ApiUsage::query()
            ->where('service', ExternalService::MillionVerifier)
            ->where('date', '>=', $weekStart->toDateString())
            ->sum('cost_units');

        // Of the agency leads that finished this week, how many had an address we could verify?
        $finished = $agencies()->where('pipeline_step', PipelineStep::Done)->where('updated_at', '>=', $weekStart->utc());
        $finishedCount = (clone $finished)->whereIn('status', [LeadStatus::Qualified, LeadStatus::Rejected])
            ->where(fn ($q) => $q->whereNull('reject_reason')->orWhereIn('reject_reason', [RejectReason::NoValidEmail, RejectReason::BelowThreshold]))
            ->count();
        $noEmail = (clone $finished)->where('reject_reason', RejectReason::NoValidEmail)->count();

        $byCountry = $agencies()->toBase()
            ->where('status', LeadStatus::Qualified->value)
            ->where('qualified_at', '>=', $today->subDays(29)->utc())
            ->selectRaw('country, count(*) as total')
            ->groupBy('country')
            ->orderByDesc('total')
            ->orderBy('country')
            ->pluck('total', 'country');

        $countries = [];
        foreach ($byCountry as $country => $total) {
            $countries[] = ['country' => (string) $country, 'count' => (int) $total];
        }

        return [
            'goal' => max(1, (int) Setting::get('agency.daily_goal')),
            'today' => $days[count($days) - 1]['qualified'],
            'inProgress' => $agencies()->whereIn('status', [LeadStatus::Discovered, LeadStatus::Enriching])->count(),
            'days' => $days,
            'creditsPerQualified' => $qualified7d === 0 ? null : round($credits7d / $qualified7d, 1),
            'emailFoundRate' => $finishedCount === 0 ? null : round(1 - $noEmail / $finishedCount, 2),
            'finished7d' => $finishedCount,
            'byCountry' => $countries,
        ];
    }
}
