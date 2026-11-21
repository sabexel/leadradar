<?php

declare(strict_types=1);

namespace App\Jobs\Sources;

use App\Actions\IngestLaravelPartnerAction;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\ScrapeRun;
use App\Services\Crawler\RobotsRules;
use App\Services\Crawler\RobotsTxtGuard;
use App\Services\Directories\LaravelPartnersService;
use App\Services\Support\DailyTarget;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Discovery source (P4.1, D108): ONE request per run — robots.txt the first time (cached 24 h), otherwise the
 * partners page, whose embedded list holds every partner. UK partners go into the pipeline.
 */
final class FetchLaravelPartnersJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithServiceLimits, Queueable;

    public function __construct(public ?int $scrapeRunId = null)
    {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return "laravel-partners:{$this->scrapeRunId}";
    }

    public function handle(LaravelPartnersService $partners, RobotsTxtGuard $robots, DailyTarget $target, IngestLaravelPartnerAction $ingest): void
    {
        if ($target->reached()) {
            return;
        }

        $wait = $robots->secondsUntilAllowed($partners->host());

        if ($wait > 0) {
            $this->release($wait);

            return;
        }

        if ($robots->cached($partners->host()) === null) {
            [$called] = $this->withinServiceLimits(fn (): RobotsRules => $partners->fetchRobotsTxt());

            if ($called) {
                $this->release(max(1, $robots->secondsUntilAllowed($partners->host())));
            }

            return;
        }

        [$called, $list] = $this->withinServiceLimits(fn (): Collection => $partners->listPartners());

        if (! $called || $list === null) {
            return;
        }

        $run = $this->scrapeRunId === null ? null : ScrapeRun::find($this->scrapeRunId);

        foreach ($list as $partner) {
            $ingest->run($partner, $run);
        }
    }
}
