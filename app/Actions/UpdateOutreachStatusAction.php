<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OutreachStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\Suppression;

/**
 * Local, sync: change a lead's outreach status (docs/07 §2 bulk actions, §3 selector; docs/10 P2.4).
 * - contacted sets `contacted_at` (first contact only — it is never moved later).
 * - not interested also puts the domain (or, without one, the company number) on the do-not-contact list.
 */
final class UpdateOutreachStatusAction
{
    public function run(Company $company, OutreachStatus $status): Company
    {
        $company->update([
            'outreach_status' => $status,
            'contacted_at' => $status !== OutreachStatus::NotContacted && $company->contacted_at === null ? now() : $company->contacted_at,
        ]);

        if ($status === OutreachStatus::NotInterested) {
            $this->suppress($company);
        }

        return $company;
    }

    private function suppress(Company $company): void
    {
        [$type, $value] = match (true) {
            $company->domain !== null => [SuppressionType::Domain, mb_strtolower($company->domain)],
            $company->registration_number !== null => [SuppressionType::CompanyNumber, mb_strtolower($company->registration_number)],
            default => [null, null],
        };

        if ($type !== null) {
            Suppression::query()->firstOrCreate(['type' => $type, 'value' => $value], ['reason' => SuppressionReason::NotInterested]);
        }
    }
}
