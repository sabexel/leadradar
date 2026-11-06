<?php

declare(strict_types=1);

use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\RunTrigger;
use App\Enums\ScrapeRunStatus;
use App\Jobs\Sources\FetchAdzunaJobsJob;
use App\Jobs\Sources\FetchReedJobsJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\ScrapeRun;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-04 06:10:00');
    config(['services.reed.key' => 'test-reed-key', 'services.adzuna.app_id' => 'test-id', 'services.adzuna.app_key' => 'test-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

// --- leadradar:discover --------------------------------------------------------------------

it('exits without dispatching when today\'s qualified count has reached the target', function () {
    Setting::put('daily_qualified_target', 2);
    Company::factory()->count(2)->qualified()->create(['qualified_at' => now()->subHour()]);

    $this->artisan('leadradar:discover')->expectsOutputToContain('already reached')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(ScrapeRun::sole()->status)->toBe(ScrapeRunStatus::StoppedTargetReached)
        ->and(ScrapeRun::sole()->finished_at)->not->toBeNull();
});

it('does not count yesterday\'s qualified leads towards today\'s target', function () {
    Setting::put('daily_qualified_target', 1);
    Company::factory()->qualified()->create(['qualified_at' => now()->subDay()]);

    $this->artisan('leadradar:discover')->assertSuccessful();

    Queue::assertPushed(FetchReedJobsJob::class);
});

it('queues page 1 on Reed and Adzuna for every keyword, tied to the run', function () {
    Setting::put('search.keywords', ['laravel', 'livewire']);

    $this->artisan('leadradar:discover')->expectsOutputToContain('started')->assertSuccessful();

    $run = ScrapeRun::sole();

    expect($run->status)->toBe(ScrapeRunStatus::Running)
        ->and($run->trigger)->toBe(RunTrigger::Schedule);

    foreach (['laravel', 'livewire'] as $keyword) {
        Queue::assertPushed(FetchReedJobsJob::class, fn ($job) => $job->keywords === $keyword && $job->page === 1 && $job->scrapeRunId === $run->id);
        Queue::assertPushed(FetchAdzunaJobsJob::class, fn ($job) => $job->keywords === $keyword && $job->page === 1 && $job->scrapeRunId === $run->id);
    }

    Queue::assertPushed(FetchReedJobsJob::class, 2);
    Queue::assertPushed(FetchAdzunaJobsJob::class, 2);
});

it('records a manual run', function () {
    $this->artisan('leadradar:discover --manual')->assertSuccessful();

    expect(ScrapeRun::sole()->trigger)->toBe(RunTrigger::Manual);
});

it('skips a job board whose key is missing and fails the run when nothing can be searched', function () {
    config(['services.adzuna.app_key' => null]);

    $this->artisan('leadradar:discover')->expectsOutputToContain('ADZUNA_APP_ID / ADZUNA_APP_KEY not set')->assertSuccessful();

    Queue::assertPushed(FetchReedJobsJob::class);
    Queue::assertNotPushed(FetchAdzunaJobsJob::class);

    ScrapeRun::query()->update(['status' => ScrapeRunStatus::Finished]);
    config(['services.reed.key' => null]);

    $this->artisan('leadradar:discover')->expectsOutputToContain('Nothing to search')->assertSuccessful();

    expect(ScrapeRun::latest('id')->first()->status)->toBe(ScrapeRunStatus::Failed);
});

it('never searches outside the UK boards\' country', function () {
    Setting::put('search.countries', ['NL']);

    $this->artisan('leadradar:discover')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('does not start a second run while one is still running', function () {
    $running = ScrapeRun::factory()->create();

    $this->artisan('leadradar:discover')->expectsOutputToContain("Run #{$running->id} is still running")->assertSuccessful();

    Queue::assertNothingPushed();
    expect(ScrapeRun::count())->toBe(1);
});

// --- leadradar:close-runs ------------------------------------------------------------------

it('closes a run once the pipeline queues are empty and records its counts', function () {
    $run = ScrapeRun::factory()->create(['started_at' => now()->subHours(2), 'signals_found' => 5, 'companies_created' => 3]);
    Company::factory()->qualified()->create(['qualified_at' => now()->subHour()]);
    Company::factory()->qualified()->create(['qualified_at' => now()->subDays(2)]); // an earlier run's lead
    Company::factory()->rejected()->create(['created_at' => now()->subHour()]);

    $this->artisan('leadradar:close-runs')->expectsOutputToContain("Run #{$run->id} finished")->assertSuccessful();

    $run->refresh();

    expect($run->status)->toBe(ScrapeRunStatus::Finished)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->qualified_count)->toBe(1)
        ->and($run->rejected_count)->toBe(1);
});

it('leaves a run open while jobs are still queued, but closes it after 20 hours', function () {
    $run = ScrapeRun::factory()->create(['started_at' => now()->subHours(2)]);
    FetchReedJobsJob::dispatch('laravel', 2, $run->id);

    $this->artisan('leadradar:close-runs')->assertSuccessful();
    expect($run->refresh()->status)->toBe(ScrapeRunStatus::Running);

    $run->update(['started_at' => now()->subHours(21)]);

    $this->artisan('leadradar:close-runs')->assertSuccessful();
    expect($run->refresh()->status)->toBe(ScrapeRunStatus::Finished);
});

// --- leadradar:rescore ---------------------------------------------------------------------

function finishedLead(LeadStatus $status, ?RejectReason $reason = null): Company
{
    $company = Company::factory()->create([
        'status' => $status, 'reject_reason' => $reason, 'pipeline_step' => PipelineStep::Done,
        'registry' => 'companies_house', 'company_status' => 'active', 'company_kind' => 'ltd',
        'accounts_category' => 'micro-entity', 'incorporated_on' => '2015-01-01',
        'laravel_evidence' => [['type' => 'laravel_mention', 'weight' => 'strong', 'detail' => 'x', 'url' => null]],
        'qualified_at' => $status === LeadStatus::Qualified ? now()->subDays(3) : null,
    ]);

    EmailAddress::factory()->for($company)->verified(EmailVerificationStatus::Ok)->create();

    return $company; // scores 3.0 (Laravel 1.0 + fit 2.0)
}

it('rescores qualified leads and near misses, but leaves hard-gate rejections and unfinished leads alone', function () {
    Setting::put('qualify_threshold', 3.0);
    $qualified = finishedLead(LeadStatus::Qualified);
    $nearMiss = finishedLead(LeadStatus::Rejected, RejectReason::BelowThreshold);
    $gated = finishedLead(LeadStatus::Rejected, RejectReason::Recruiter);
    $enriching = Company::factory()->create(['status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::Crawl]);

    $this->artisan('leadradar:rescore')->expectsOutputToContain('Rescored: 2 qualified, 0 rejected')->assertSuccessful();

    expect($qualified->refresh()->score)->toBe('3.0')
        ->and($qualified->qualified_at->lessThan(now()->subDays(2)))->toBeTrue() // first qualification time kept
        ->and($nearMiss->refresh()->status)->toBe(LeadStatus::Qualified)       // promoted by the lower threshold
        ->and($gated->refresh()->reject_reason)->toBe(RejectReason::Recruiter)
        ->and($gated->score)->toBeNull()
        ->and($enriching->refresh()->status)->toBe(LeadStatus::Enriching);

    Queue::assertNothingPushed();
});

// --- Schedule ------------------------------------------------------------------------------

it('schedules the worker, discovery, run closing and pruning as in docs/08', function () {
    $events = collect(app(Schedule::class)->events())->mapWithKeys(fn (Event $e) => [
        trim(str_replace(["'".PHP_BINARY."'", PHP_BINARY, '"'.PHP_BINARY.'"', "'artisan'", 'artisan'], '', $e->command)) => $e,
    ]);

    $find = fn (string $needle) => $events->first(fn (Event $e, string $cmd) => str_contains($cmd, $needle));

    expect($find('queue:work --queue=registry,verify,web,sources --stop-when-empty --max-time=50')->expression)->toBe('* * * * *')
        ->and($find('queue:work')->withoutOverlapping)->toBeTrue()
        ->and($find('queue:work')->runInBackground)->toBeTrue()
        ->and($find('leadradar:discover')->expression)->toBe('10 6 * * *')
        ->and($find('leadradar:discover')->timezone)->toBe('Europe/London')
        ->and($find('leadradar:close-runs')->expression)->toBe('*/15 * * * *')
        ->and($find('leadradar:report-failed-jobs')->expression)->toBe('0 7 * * *')
        ->and($find('leadradar:report-failed-jobs')->timezone)->toBe('Europe/London')
        ->and($find('queue:prune-failed --hours=168'))->not->toBeNull()
        ->and($find('model:prune'))->not->toBeNull();
});
