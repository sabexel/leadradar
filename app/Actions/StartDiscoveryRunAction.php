<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RunTrigger;
use App\Enums\ScrapeRunStatus;
use App\Jobs\Sources\FetchAdzunaJobsJob;
use App\Jobs\Sources\FetchReedJobsJob;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Services\Support\DailyTarget;

/**
 * Local, sync: start a discovery run (docs/05 "Discovery run"). Used by `leadradar:discover` and, later,
 * the dashboard's "Run now" button.
 */
final class StartDiscoveryRunAction
{
    public function __construct(private readonly DailyTarget $target) {}

    /**
     * @return array{run: ScrapeRun|null, dispatched: list<string>, skipped: list<string>, message: string}
     */
    public function run(RunTrigger $trigger = RunTrigger::Schedule): array
    {
        $running = ScrapeRun::query()->where('status', ScrapeRunStatus::Running)->latest('id')->first();

        if ($running !== null) {
            return ['run' => $running, 'dispatched' => [], 'skipped' => [], 'message' => "Run #{$running->id} is still running — not starting another."];
        }

        $run = ScrapeRun::create(['started_at' => now(), 'trigger' => $trigger, 'status' => ScrapeRunStatus::Running]);

        if ($this->target->reached()) {
            $run->update(['status' => ScrapeRunStatus::StoppedTargetReached, 'finished_at' => now(), 'qualified_count' => 0]);

            return ['run' => $run, 'dispatched' => [], 'skipped' => [], 'message' => "Today's target of {$this->target->target()} qualified leads is already reached."];
        }

        $keywords = array_values(array_filter((array) Setting::get('search.keywords'), fn (mixed $k): bool => is_string($k) && trim($k) !== ''));
        $countries = array_map('strtoupper', (array) Setting::get('search.countries'));
        $dispatched = [];
        $skipped = [];

        // Reed and Adzuna (gb) are both UK job boards.
        if (! in_array('GB', $countries, true)) {
            $skipped[] = 'reed + adzuna: GB not in search.countries';
        } else {
            $reed = filled(config('services.reed.key'));
            $adzuna = filled(config('services.adzuna.app_id')) && filled(config('services.adzuna.app_key'));

            foreach ($keywords as $keyword) {
                if ($reed) {
                    FetchReedJobsJob::dispatch($keyword, 1, $run->id);
                    $dispatched[] = "reed:{$keyword}";
                }

                if ($adzuna) {
                    FetchAdzunaJobsJob::dispatch($keyword, 1, $run->id);
                    $dispatched[] = "adzuna:{$keyword}";
                }
            }

            if (! $reed) {
                $skipped[] = 'reed: REED_API_KEY not set';
            }

            if (! $adzuna) {
                $skipped[] = 'adzuna: ADZUNA_APP_ID / ADZUNA_APP_KEY not set';
            }
        }

        if ($dispatched === []) {
            $run->update(['status' => ScrapeRunStatus::Failed, 'finished_at' => now()]);

            return ['run' => $run, 'dispatched' => [], 'skipped' => $skipped, 'message' => 'Nothing to search: check keys, search.keywords and search.countries.'];
        }

        return ['run' => $run, 'dispatched' => $dispatched, 'skipped' => $skipped, 'message' => "Run #{$run->id} started."];
    }
}
