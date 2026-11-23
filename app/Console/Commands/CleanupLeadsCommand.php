<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CleanupLeadsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:cleanup {--stuck-hours=6 : restart leads untouched this long}')]
#[Description('Reject dead websites, retry timed-out leads, restart stuck ones, fix agency names (D124)')]
final class CleanupLeadsCommand extends Command
{
    public function handle(CleanupLeadsAction $cleanup): int
    {
        $counts = $cleanup->run(max(1, (int) $this->option('stuck-hours')));

        $this->info(sprintf(
            'Dead websites rejected: %d · retried: %d · gave up (retried twice): %d · stuck restarted: %d · renamed: %d',
            $counts['dead_sites'],
            $counts['retried'],
            $counts['gave_up'],
            $counts['restarted'],
            $counts['renamed'],
        ));

        return self::SUCCESS;
    }
}
