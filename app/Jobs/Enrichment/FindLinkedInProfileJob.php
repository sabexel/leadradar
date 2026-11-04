<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyLinkedInResultAction;
use App\DTOs\Search\SearchResult;
use App\Enums\ExternalService;
use App\Enums\PipelineStep;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Company;
use App\Models\Person;
use App\Services\Search\BraveSearchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * find_linkedin: ONE Brave query for one founder. Never requests linkedin.com (CLAUDE.md hard rule).
 */
final class FindLinkedInProfileJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId, public int $personId)
    {
        $this->onQueue('web');
    }

    public function uniqueId(): string
    {
        return "linkedin:{$this->personId}";
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
        return PipelineStep::FindLinkedIn;
    }

    public function handle(BraveSearchService $brave, ApplyLinkedInResultAction $linkedIn, AdvanceLeadPipelineAction $advance): void
    {
        $company = Company::find($this->companyId);
        $person = Person::query()->where('company_id', $this->companyId)->find($this->personId);

        if ($company === null || $person === null || ! $company->isAwaiting($this->step())) {
            return;
        }

        // Already handled (e.g. a retry of this job) → no second query.
        if ($linkedIn->nextPerson($company)?->id === $person->id) {
            $companyName = $linkedIn->companyQueryName($company);

            [$called, $result] = $this->withinServiceLimits(
                fn (): ?SearchResult => $brave->findLinkedInProfile($person->first_name, $person->last_name, $companyName),
            );

            if (! $called) {
                return;
            }

            $linkedIn->apply($person, $result);
        }

        $advance->run($company->refresh());
    }
}
