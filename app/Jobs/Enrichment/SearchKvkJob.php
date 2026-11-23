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
use App\Services\Registries\KvkService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Collection;

/**
 * registry_search (NL): find the company in the KVK register by name. Free call.
 */
final class SearchKvkJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('registry');
    }

    public function uniqueId(): string
    {
        return "kvk-search:{$this->companyId}";
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
        return PipelineStep::RegistrySearch;
    }

    public function handle(KvkService $kvk, ApplyCompaniesHouseResultAction $apply, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step())) {
            return;
        }

        [$called, $results] = $this->withinServiceLimits(
            fn (): Collection => $kvk->searchCompanies($company->name),
        );

        if (! $called || $results === null) {
            return;
        }

        $company = $apply->applySearch($company, $results, Registry::Kvk);

        if ($company !== null) {
            $advance->run($company->refresh());
        }
    }
}
