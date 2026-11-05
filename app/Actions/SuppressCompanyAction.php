<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\RejectReason;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\Suppression;

/**
 * Local, sync: "Suppress domain" from the leads table. The domain goes on the do-not-contact list and the
 * company stops where it is (queued jobs re-check suppression before any paid call, D84).
 */
final class SuppressCompanyAction
{
    /**
     * @return bool false when the company has no domain to suppress
     */
    public function run(Company $company, SuppressionReason $reason = SuppressionReason::Manual): bool
    {
        if ($company->domain === null) {
            return false;
        }

        Suppression::query()->firstOrCreate(
            ['type' => SuppressionType::Domain, 'value' => mb_strtolower($company->domain)],
            ['reason' => $reason],
        );

        if ($company->status !== LeadStatus::Suppressed) {
            $company->update(['status' => LeadStatus::Suppressed, 'reject_reason' => RejectReason::Suppressed]);
            $company->recordEvent($company->pipeline_step, PipelineOutcome::Rejected, 'Domain suppressed by hand', ['reason' => $reason->value]);
        }

        return true;
    }
}
