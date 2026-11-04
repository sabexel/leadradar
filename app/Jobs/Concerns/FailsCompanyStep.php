<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use Throwable;

/**
 * For enrichment jobs: when the job finally fails, the company gets status `error` and a failed
 * timeline entry, so the dashboard can offer "Retry step" (docs/05). Requires `$companyId`.
 */
trait FailsCompanyStep
{
    abstract protected function step(): PipelineStep;

    public function failed(?Throwable $exception): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || $company->isFinished()) {
            return;
        }

        $company->update(['status' => LeadStatus::Error]);
        $company->recordEvent($this->step(), PipelineOutcome::Failed, $exception?->getMessage() ?? 'Job failed', [
            'job' => class_basename($this),
            'exception' => $exception === null ? null : class_basename($exception),
        ]);
    }
}
