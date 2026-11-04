<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CloseScrapeRunsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:close-runs')]
#[Description('Finish discovery runs whose queued work is done and record their counts')]
final class CloseRunsCommand extends Command
{
    public function handle(CloseScrapeRunsAction $close): int
    {
        foreach ($close->run() as $run) {
            $this->info("Run #{$run->id} finished: {$run->signals_found} signals, {$run->companies_created} companies, {$run->qualified_count} qualified, {$run->rejected_count} rejected.");
        }

        return self::SUCCESS;
    }
}
