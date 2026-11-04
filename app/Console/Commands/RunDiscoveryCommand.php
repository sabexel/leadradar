<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\StartDiscoveryRunAction;
use App\Enums\RunTrigger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:discover {--manual : Record the run as started by hand}')]
#[Description('Start a discovery run: search the job boards unless today\'s qualified-lead target is reached')]
final class RunDiscoveryCommand extends Command
{
    public function handle(StartDiscoveryRunAction $discovery): int
    {
        $result = $discovery->run($this->option('manual') ? RunTrigger::Manual : RunTrigger::Schedule);

        $this->info($result['message']);

        foreach ($result['dispatched'] as $search) {
            $this->line("  queued {$search}");
        }

        foreach ($result['skipped'] as $reason) {
            $this->warn("  skipped {$reason}");
        }

        return self::SUCCESS;
    }
}
