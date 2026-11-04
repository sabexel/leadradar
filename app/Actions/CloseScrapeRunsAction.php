<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Enums\ScrapeRunStatus;
use App\Models\Company;
use App\Models\ScrapeRun;
use Illuminate\Support\Facades\Queue;

/**
 * Local, sync (`leadradar:close-runs`): finish running runs once the pipeline queues are empty (D79).
 */
final class CloseScrapeRunsAction
{
    public const QUEUES = ['sources', 'registry', 'web', 'verify'];

    /**
     * A run older than this is closed even if work is still queued (e.g. jobs released until tomorrow by a cap).
     */
    public const MAX_HOURS = 20;

    /**
     * @return list<ScrapeRun> runs that were closed
     */
    public function run(): array
    {
        $runs = ScrapeRun::query()->where('status', ScrapeRunStatus::Running)->orderBy('id')->get();

        if ($runs->isEmpty()) {
            return [];
        }

        $queued = array_sum(array_map(fn (string $queue): int => Queue::size($queue), self::QUEUES));
        $closed = [];

        foreach ($runs as $run) {
            $timedOut = $run->started_at->lessThan(now()->subHours(self::MAX_HOURS));

            if ($queued > 0 && ! $timedOut) {
                continue;
            }

            $run->update([
                'status' => ScrapeRunStatus::Finished,
                'finished_at' => now(),
                'qualified_count' => Company::query()
                    ->where('status', LeadStatus::Qualified)
                    ->where('qualified_at', '>=', $run->started_at)
                    ->count(),
                'rejected_count' => Company::query()
                    ->where('status', LeadStatus::Rejected)
                    ->where('created_at', '>=', $run->started_at)
                    ->count(),
            ]);

            $closed[] = $run;
        }

        return $closed;
    }
}
