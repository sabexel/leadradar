<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\LeadStatus;
use App\Models\Company;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Local: has today's qualified-lead target (`daily_qualified_target`) been reached?
 * "Today" is the London business day (D20).
 */
final class DailyTarget
{
    public function target(): int
    {
        return (int) Setting::get('daily_qualified_target');
    }

    public function qualifiedToday(): int
    {
        $timezone = (string) config('leadradar.timezone', 'Europe/London');
        $start = CarbonImmutable::now($timezone)->startOfDay();

        return Company::query()
            ->where('status', LeadStatus::Qualified)
            ->where('qualified_at', '>=', $start->utc())
            ->where('qualified_at', '<', $start->addDay()->utc())
            ->count();
    }

    public function reached(): bool
    {
        return $this->qualifiedToday() >= $this->target();
    }
}
