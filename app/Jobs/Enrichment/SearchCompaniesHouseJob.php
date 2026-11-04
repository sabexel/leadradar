<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCompaniesHouseResultAction;
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
use Illuminate\Support\Collection;

/**
 * registry_search: find the company on Companies House by name.
 */
final class SearchCompaniesHouseJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('registry');
    }

    public function uniqueId(): string
    {
        return "ch-search:{$this->companyId}";
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
        return PipelineStep::RegistrySearch;
    }

    public function handle(CompaniesHouseService $companiesHouse, ApplyCompaniesHouseResultAction $apply, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step())) {
            return;
        }

        [$called, $results] = $this->withinServiceLimits(
            fn (): Collection => $companiesHouse->searchCompanies($company->name),
        );

        if (! $called || $results === null) {
            return;
        }

        $company = $apply->applySearch($company, $results);

        if ($company !== null) {
            $advance->run($company->refresh());
        }
    }
}
