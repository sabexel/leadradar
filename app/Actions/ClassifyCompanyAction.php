<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Classification\Classification;
use App\DTOs\Classification\ClassificationInput;
use App\DTOs\Classification\DetectedSignal;
use App\Enums\CompanyType;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Models\Company;
use App\Models\Person;
use App\Models\Signal;
use App\Services\Classification\CompanyClassifier;

/**
 * Local, sync: classify a company from what we know so far and apply the recruiter/offshore gates.
 *
 * Runs at the `classify` step (registry + job-ad data) and again after the crawl with page text (P1.7).
 */
final class ClassifyCompanyAction
{
    public function __construct(private readonly CompanyClassifier $classifier) {}

    /**
     * @param  list<string>  $siteTexts
     */
    public function run(Company $company, array $siteTexts = []): Classification
    {
        $result = $this->classifier->classify(new ClassificationInput(
            name: $company->legal_name ?? $company->name,
            sicCodes: $company->sic_codes ?? [],
            adTexts: array_values($company->signals()->get()
                ->map(fn (Signal $s): string => trim($s->title.' '.($s->description ?? '')))
                ->all()),
            officerCountries: array_values($company->founders()->get()
                ->map(fn (Person $p): ?string => $p->country_of_residence)
                ->all()),
            siteTexts: $siteTexts,
        ));

        $company->update([
            'company_type' => $result->type,
            'offshore_evidence' => $result->offshoreSignals === []
                ? null
                : array_map(fn (DetectedSignal $s): array => $s->toArray(), $result->offshoreSignals),
        ]);

        $context = ['type' => $result->type->value, 'reasons' => $result->reasons];

        match ($result->type) {
            CompanyType::Recruiter => $company->reject(RejectReason::Recruiter, 'Recruitment agency', $context),
            CompanyType::Offshore => $company->reject(RejectReason::Offshore, 'Offshore development shop', $context),
            default => $company->recordEvent(PipelineStep::Classify, PipelineOutcome::Ok, "Classified as {$result->type->value}", $context),
        };

        return $result;
    }
}
