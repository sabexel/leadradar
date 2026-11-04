<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\QualifyCompanyAction;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Models\Company;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:rescore')]
#[Description('Re-run the gates and score for finished leads (qualified + near misses) after changing the rules')]
final class RescoreCompaniesCommand extends Command
{
    /**
     * Local only — no jobs, no API calls (docs/05 "Re-scoring").
     */
    public function handle(QualifyCompanyAction $qualify): int
    {
        $counts = ['qualified' => 0, 'rejected' => 0];

        Company::query()
            ->where('pipeline_step', PipelineStep::Done)
            ->where(fn ($q) => $q->where('status', LeadStatus::Qualified)
                ->orWhere(fn ($q) => $q->where('status', LeadStatus::Rejected)->where('reject_reason', RejectReason::BelowThreshold)))
            ->orderBy('id')
            ->chunkById(100, function ($companies) use ($qualify, &$counts): void {
                foreach ($companies as $company) {
                    $qualify->run($company);
                    $counts[$company->status === LeadStatus::Qualified ? 'qualified' : 'rejected']++;
                }
            });

        $this->info("Rescored: {$counts['qualified']} qualified, {$counts['rejected']} rejected.");

        return self::SUCCESS;
    }
}
