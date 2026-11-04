<?php

declare(strict_types=1);

namespace App\Jobs\Enrichment;

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyCrawlResultAction;
use App\DTOs\Crawl\CrawlResult;
use App\Enums\PipelineStep;
use App\Exceptions\ExternalServiceException;
use App\Jobs\Concerns\FailsCompanyStep;
use App\Jobs\Concerns\InteractsWithServiceLimits;
use App\Models\Company;
use App\Services\Crawler\CrawlPlanner;
use App\Services\Crawler\RobotsRules;
use App\Services\Crawler\RobotsTxtGuard;
use App\Services\Crawler\WebsiteCrawlerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * crawl: ONE request per run — robots.txt the first time a domain is seen, otherwise one page.
 * The pipeline queues the next page after each run until the page cap (docs/05).
 */
final class CrawlCompanyWebsiteJob implements ShouldBeUnique, ShouldQueue
{
    use FailsCompanyStep, InteractsWithServiceLimits, Queueable;

    public function __construct(public int $companyId, public string $url)
    {
        $this->onQueue('web');
    }

    public function uniqueId(): string
    {
        return "crawl:{$this->companyId}:".md5($this->url);
    }

    /**
     * One company ↔ one domain (D54), so this is the per-domain lock docs/05 asks for.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("crawl:{$this->companyId}"))->releaseAfter(5)->expireAfter(60)];
    }

    protected function step(): PipelineStep
    {
        return PipelineStep::Crawl;
    }

    public function handle(
        WebsiteCrawlerService $crawler,
        RobotsTxtGuard $robots,
        CrawlPlanner $planner,
        ApplyCrawlResultAction $apply,
        AdvanceLeadPipelineAction $advance,
    ): void {
        $company = Company::find($this->companyId);

        if ($company === null || ! $company->isAwaiting($this->step()) || $company->domain === null || $company->website === null) {
            return;
        }

        $wait = $robots->secondsUntilAllowed($company->domain);

        if ($wait > 0) {
            $this->release($wait);

            return;
        }

        // First request to this domain: robots.txt is this run's one call; the page comes next run.
        if ($robots->cached($company->domain) === null) {
            [$called] = $this->withinServiceLimits(fn (): RobotsRules => $crawler->fetchRobotsTxt($company));

            if ($called) {
                $this->release(max(1, $robots->secondsUntilAllowed($company->domain)));
            }

            return;
        }

        try {
            [$called, $result] = $this->withinServiceLimits(fn (): CrawlResult => $crawler->fetchPage($company, $this->url));
        } catch (ExternalServiceException $e) {
            // An unreachable inner page is data; an unreachable home page means retry, then error.
            if ($this->url === $planner->homeUrl($company)) {
                throw $e;
            }

            [$called, $result] = [true, CrawlResult::skipped($this->url, $planner->pageTypeOf($this->url), 'unreachable')];
        }

        if (! $called || $result === null) {
            return;
        }

        $apply->apply($company, $result);
        $advance->run($company->refresh());
    }
}
