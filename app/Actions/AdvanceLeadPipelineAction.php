<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Jobs\Enrichment\FetchCompanyOfficersJob;
use App\Jobs\Enrichment\FetchCompanyProfileJob;
use App\Jobs\Enrichment\FindCompanyWebsiteJob;
use App\Jobs\Enrichment\FindLinkedInProfileJob;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Jobs\Enrichment\VerifyEmailJob;
use App\Models\Company;
use App\Services\Crawler\CrawlPlanner;
use App\Services\Support\SuppressionList;
use App\Services\Verification\VerificationPlanner;

/**
 * Local state machine: decides the next pipeline step for a company (docs/05).
 *
 * Local steps (classify, …) run inline; the machine keeps going until it reaches a step that needs an
 * outbound call, dispatches that job and stops. Built up step by step (D37): suppression gate (P1.3),
 * registry (P1.4), classify (P1.5); P1.12 completes it.
 */
final class AdvanceLeadPipelineAction
{
    /**
     * More than enough for every local step in a row; stops a bug from looping forever.
     */
    private const MAX_LOCAL_STEPS = 10;

    public function __construct(
        private readonly SuppressionList $suppressions,
        private readonly ClassifyCompanyAction $classify,
        private readonly ApplyWebsiteResultAction $websites,
        private readonly ApplyCrawlResultAction $crawl,
        private readonly CrawlPlanner $crawlPlanner,
        private readonly BuildFounderEmailCandidatesAction $emailCandidates,
        private readonly VerificationPlanner $verificationPlanner,
        private readonly ApplyVerificationResultAction $verification,
        private readonly ApplyLinkedInResultAction $linkedIn,
    ) {}

    public function run(Company $company): void
    {
        for ($i = 0; $i < self::MAX_LOCAL_STEPS; $i++) {
            if ($company->isFinished() || $this->suppressIfListed($company)) {
                return;
            }

            if ($this->runLocalStep($company)) {
                $company->refresh();

                continue;
            }

            $job = $this->jobFor($company);

            if ($job !== null) {
                if ($company->status === LeadStatus::Discovered) {
                    $company->update(['status' => LeadStatus::Enriching]);
                }

                dispatch($job);
            }

            return;
        }
    }

    /**
     * Suppressed companies get no further spend, at any step.
     */
    private function suppressIfListed(Company $company): bool
    {
        $match = $this->suppressions->matchFor($company);

        if ($match === null) {
            return false;
        }

        $company->update([
            'status' => LeadStatus::Suppressed,
            'reject_reason' => RejectReason::Suppressed,
        ]);

        $company->recordEvent($company->pipeline_step, PipelineOutcome::Rejected, 'Suppressed', [
            'type' => $match->type->value,
            'reason' => $match->reason->value,
        ]);

        return true;
    }

    /**
     * Runs the current step if it is local. Returns true when a step ran (the company may have moved on
     * or been rejected), false when the current step needs a job.
     */
    private function runLocalStep(Company $company): bool
    {
        switch ($company->pipeline_step) {
            case PipelineStep::Classify:
                $this->classify->run($company);

                if (! $company->isFinished()) {
                    $company->update(['pipeline_step' => PipelineStep::FindWebsite]);
                }

                return true;

            case PipelineStep::FindWebsite:
                if ($company->website === null) {
                    return false; // needs the search job
                }

                $this->websites->applyKnownWebsite($company);

                return true;

            case PipelineStep::Crawl:
                if ($this->crawlPlanner->next($company) !== null) {
                    return false; // more pages to fetch
                }

                $this->crawl->finish($company);

                return true;

            case PipelineStep::BuildEmailCandidates:
                $this->emailCandidates->run($company);

                return true;

            case PipelineStep::VerifyEmails:
                if ($this->verificationPlanner->next($company) !== null) {
                    return false; // more addresses worth a credit
                }

                $this->verification->finish($company);

                return true;

            case PipelineStep::FindLinkedIn:
                if ($this->linkedIn->nextPerson($company) !== null) {
                    return false; // another founder to look up
                }

                $this->linkedIn->finish($company);

                return true;

            default:
                return false;
        }
    }

    private function jobFor(Company $company): ?object
    {
        // Only UK companies have a registry integration so far (NL/US come in P4.2).
        $registry = $company->country === 'GB';

        return match ($company->pipeline_step) {
            PipelineStep::RegistrySearch => $registry ? new SearchCompaniesHouseJob($company->id) : null,
            PipelineStep::RegistryProfile => $registry ? new FetchCompanyProfileJob($company->id) : null,
            PipelineStep::RegistryOfficers => $registry ? new FetchCompanyOfficersJob($company->id) : null,
            PipelineStep::FindWebsite => new FindCompanyWebsiteJob($company->id),
            PipelineStep::Crawl => ($url = $this->crawlPlanner->next($company)) === null ? null : new CrawlCompanyWebsiteJob($company->id, $url),
            PipelineStep::VerifyEmails => ($email = $this->verificationPlanner->next($company)) === null ? null : new VerifyEmailJob($company->id, $email->id),
            PipelineStep::FindLinkedIn => ($person = $this->linkedIn->nextPerson($company)) === null ? null : new FindLinkedInProfileJob($company->id, $person->id),
            default => null, // `qualify` is wired in with P1.11
        };
    }
}
