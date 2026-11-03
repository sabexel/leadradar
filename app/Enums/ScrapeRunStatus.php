<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a discovery run.
 */
enum ScrapeRunStatus: string
{
    case Running = 'running';
    case Finished = 'finished';
    case StoppedTargetReached = 'stopped_target_reached';
    case Failed = 'failed';
}
