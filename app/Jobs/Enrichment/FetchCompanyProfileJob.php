<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCompaniesHouseResultAction;
use App\DTOs\CompaniesHouse\CompanyProfile;
use App\Enums\ExternalService;
use App\Enums\PipelineStep;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Company;
use App\Services\CompaniesHouse\CompaniesHouseService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * registry_profile: status, legal form, SIC codes, accounts → registry gates.
 */
final class FetchCompanyProfileJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('registry');
    }

    public function uniqueId(): string
    {
        return "ch-profile:{$this->companyId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::CompaniesHouse->value)];
    }

    protected function step(): PipelineStep
    {
        return PipelineStep::RegistryProfile;
    }

    public function handle(CompaniesHouseService $companiesHouse, ApplyCompaniesHouseResultAction $apply, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step()) || $company->registration_number === null) {
            return;
        }

        $number = $company->registration_number;

        [$called, $profile] = $this->withinServiceLimits(
            fn (): ?CompanyProfile => $companiesHouse->getProfile($number),
        );

        if (! $called) {
            return;
        }

        $advance->run($apply->applyProfile($company, $profile)->refresh());
    }
}
