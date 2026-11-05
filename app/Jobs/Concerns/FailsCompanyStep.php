<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Actions\AdvanceLeadPipelineAction;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Services\Support\SuppressionList;
use Throwable;

/**
 * For enrichment jobs: when the job finally fails, the company gets status `error` and a failed
 * timeline entry, so the dashboard can offer "Retry step" (docs/05). Requires `$companyId`.
 */
trait FailsCompanyStep
{
    abstract protected function step(): PipelineStep;

    /**
     * Last check before spending money: the company may have been suppressed (e.g. by an import) after this
     * job was queued. If so, the pipeline marks it suppressed and the job makes no call.
     */
    protected function stopIfSuppressed(Company $company, AdvanceLeadPipelineAction $advance): bool
    {
        if (! app(SuppressionList::class)->isSuppressed($company)) {
            return false;
        }

        $advance->run($company);

        return true;
    }

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
