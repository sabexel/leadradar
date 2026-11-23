<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCompaniesHouseResultAction;
use App\DTOs\Registries\RegistryCompanyDetails;
use App\Enums\ExternalService;
use App\Enums\PipelineStep;
use App\Enums\Registry;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Company;
use App\Services\Registries\OpenCorporatesService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * registry_profile (US): one OpenCorporates call gives the profile and the officers, so this job applies both
 * registry_profile and registry_officers.
 */
final class FetchOpenCorporatesCompanyJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('registry');
    }

    public function uniqueId(): string
    {
        return "oc-company:{$this->companyId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::OpenCorporates->value)];
    }

    protected function step(): PipelineStep
    {
        return PipelineStep::RegistryProfile;
    }

    public function handle(OpenCorporatesService $openCorporates, ApplyCompaniesHouseResultAction $apply, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step()) || $company->registration_number === null) {
            return;
        }

        $number = $company->registration_number;

        [$called, $details] = $this->withinServiceLimits(
            fn (): ?RegistryCompanyDetails => $openCorporates->getCompany($number),
        );

        if (! $called) {
            return;
        }

        $company = $apply->applyProfile($company, $details?->profile, Registry::OpenCorporates)->refresh();

        if ($details !== null && $company->pipeline_step === PipelineStep::RegistryOfficers) {
            $company = $apply->applyOfficers($company, $details->officers, Registry::OpenCorporates)->refresh();
        }

        $advance->run($company);
    }
}
