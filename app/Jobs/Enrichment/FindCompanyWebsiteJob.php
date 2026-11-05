<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyWebsiteResultAction;
use App\DTOs\Search\SearchResult;
use App\Enums\ExternalService;
use App\Enums\PipelineStep;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Company;
use App\Services\Search\BraveSearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * find_website: one Brave query for the company's own website.
 */
final class FindCompanyWebsiteJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId)
    {
        $this->onQueue('web');
    }

    public function uniqueId(): string
    {
        return "find-website:{$this->companyId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(ExternalService::Brave->value)];
    }

    protected function step(): PipelineStep
    {
        return PipelineStep::FindWebsite;
    }

    public function handle(BraveSearchService $brave, ApplyWebsiteResultAction $apply, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step()) || $company->website !== null) {
            return;
        }

        if ($this->stopIfSuppressed($company, $advance)) {
            return;
        }

        $name = $company->legal_name ?? $company->name;
        $town = trim(explode(',', (string) ($company->location ?? ($company->registered_address['locality'] ?? '')))[0]);

        [$called, $result] = $this->withinServiceLimits(
            fn (): ?SearchResult => $brave->findCompanyWebsite($name, $town === '' ? null : $town),
        );

        if (! $called) {
            return;
        }

        $advance->run($apply->applySearchResult($company, $result)->refresh());
    }
}
