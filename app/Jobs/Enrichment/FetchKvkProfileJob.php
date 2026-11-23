<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCompaniesHouseResultAction;
use App\DTOs\CompaniesHouse\CompanyProfile;
use App\Enums\ExternalService;
use App\Enums\PipelineStep;
use App\Enums\Registry;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Company;
use App\Services\Registries\KvkService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * registry_profile (NL): KVK basisprofiel → registry gates. Paid call (€0.02); KVK has no officers step.
 */
final class FetchKvkProfileJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('registry');
    }

    public function uniqueId(): string
    {
        return "kvk-profile:{$this->companyId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Kvk->value)];
    }

    protected function step(): PipelineStep
    {
        return PipelineStep::RegistryProfile;
    }

    public function handle(KvkService $kvk, ApplyCompaniesHouseResultAction $apply, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step()) || $company->registration_number === null) {
            return;
        }

        $number = $company->registration_number;

        [$called, $profile] = $this->withinServiceLimits(
            fn (): ?CompanyProfile => $kvk->getProfile($number),
        );

        if (! $called) {
            return;
        }

        $advance->run($apply->applyProfile($company, $profile, Registry::Kvk)->refresh());
    }
}
