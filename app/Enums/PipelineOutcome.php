<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of a pipeline step, recorded as a PipelineEvent.
 */
enum PipelineOutcome: string
{
    case Ok = 'ok';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
