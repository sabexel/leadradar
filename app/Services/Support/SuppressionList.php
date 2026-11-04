<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\Suppression;

/**
 * Local: is this company on the do-not-contact list (domain, company number or any of its emails)?
 * Checked before any enrichment spend (docs/03, docs/09).
 */
final class SuppressionList
{
    public function matchFor(Company $company): ?Suppression
    {
        $emails = $company->emailAddresses()->pluck('email')->all();

        return Suppression::query()
            ->where(function ($query) use ($company, $emails) {
                if ($company->domain !== null) {
                    $query->orWhere(fn ($q) => $q->where('type', SuppressionType::Domain)->where('value', mb_strtolower($company->domain)));
                }

                if ($company->registration_number !== null) {
                    $query->orWhere(fn ($q) => $q->where('type', SuppressionType::CompanyNumber)->where('value', mb_strtolower($company->registration_number)));
                }

                if ($emails !== []) {
                    $query->orWhere(fn ($q) => $q->where('type', SuppressionType::Email)->whereIn('value', $emails));
                }

                // No identifiers yet → nothing can match.
                $query->orWhereRaw('1 = 0');
            })
            ->first();
    }

    public function isSuppressed(Company $company): bool
    {
        return $this->matchFor($company) !== null;
    }
}
