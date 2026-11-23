<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Models\Company;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('knows which connection failures are permanent', function (string $message, bool $permanent) {
    expect(CrawlCompanyWebsiteJob::isPermanentFailure($message))->toBe($permanent);
})->with([
    ['[crawler] connection failed: cURL error 6: Could not resolve host: x.test', true],
    ['[crawler] connection failed: cURL error 60: SSL: no alternative certificate subject name matches', true],
    ['[crawler] connection failed: cURL error 35: TLS connect error', true],
    ['[crawler] connection failed: Will not follow more than 3 redirects', true],
    ['[crawler] connection failed: cURL error 28: Operation timed out after 10009 milliseconds', false],
    ['[crawler] connection failed: cURL error 7: Failed to connect', false],
]);

it('rejects dead websites, retries timeouts at most twice, and restarts stuck leads', function () {
    $dead = Company::factory()->create(['status' => LeadStatus::Error, 'pipeline_step' => PipelineStep::Crawl]);
    $dead->recordEvent(PipelineStep::Crawl, PipelineOutcome::Failed, '[crawler] connection failed: cURL error 6: Could not resolve host: x.test');

    $timeout = Company::factory()->create(['status' => LeadStatus::Error, 'pipeline_step' => PipelineStep::Crawl]);
    $timeout->recordEvent(PipelineStep::Crawl, PipelineOutcome::Failed, '[crawler] connection failed: cURL error 28: Operation timed out');

    $hopeless = Company::factory()->create(['status' => LeadStatus::Error, 'pipeline_step' => PipelineStep::Crawl]);
    $hopeless->recordEvent(PipelineStep::Crawl, PipelineOutcome::Failed, 'cURL error 28: timed out');
    $hopeless->recordEvent(PipelineStep::Crawl, PipelineOutcome::Ok, 'Retry requested from crawl');
    $hopeless->recordEvent(PipelineStep::Crawl, PipelineOutcome::Ok, 'Retry requested from crawl');

    $stuck = Company::factory()->discovered()->create(['status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::RegistrySearch]);
    Company::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHours(8)]);

    $this->artisan('leadradar:cleanup')
        ->expectsOutputToContain('Dead websites rejected: 1 · retried: 1 · gave up (retried twice): 1 · stuck restarted:')
        ->assertSuccessful();

    expect($dead->refresh()->reject_reason)->toBe(RejectReason::NoWebsite)
        ->and($timeout->refresh()->status)->toBe(LeadStatus::Enriching)
        ->and($hopeless->refresh()->status)->toBe(LeadStatus::Error);

    Queue::assertPushed(SearchCompaniesHouseJob::class, fn ($job) => $job->companyId === $stuck->id);
});
