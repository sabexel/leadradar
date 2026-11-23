<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCompaniesHouseResultAction;
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
use Illuminate\Support\Collection;

/**
 * registry_search (US): find the company in the US state registries through OpenCorporates.
 */
final class SearchOpenCorporatesJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('registry');
    }

    public function uniqueId(): string
    {
        return "oc-search:{$this->companyId}";
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
        return PipelineStep::RegistrySearch;
    }

    public function handle(OpenCorporatesService $openCorporates, ApplyCompaniesHouseResultAction $apply, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step())) {
            return;
        }

        [$called, $results] = $this->withinServiceLimits(
            fn (): Collection => $openCorporates->searchCompanies($company->name),
        );

        if (! $called || $results === null) {
            return;
        }

        $company = $apply->applySearch($company, $results, Registry::OpenCorporates);

        if ($company !== null) {
            $advance->run($company->refresh());
        }
    }
}
