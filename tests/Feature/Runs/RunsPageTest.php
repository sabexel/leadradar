<?php

declare(strict_types=1);

use App\Enums\ExternalService;
use App\Enums\RunTrigger;
use App\Enums\ScrapeRunStatus;
use App\Jobs\Sources\FetchReedJobsJob;
use App\Models\ApiUsage;
use App\Models\ScrapeRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-07-15 13:00:00', 'UTC'));
    $this->actingAs(User::factory()->create());
});

function failedJob(string $class = 'App\\Jobs\\Enrichment\\CrawlCompanyWebsiteJob'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'web',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => $class, 'command' => 'O:8:"stdClass":0:{}'], 'attempts' => 3]),
        'exception' => "RuntimeException: Connection timed out in /app/Services/Crawler.php:10\nStack trace:\n#0 ...",
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('redirects guests', function () {
    auth()->logout();

    $this->get(route('runs.index'))->assertRedirect(route('login'));
});

it('lists runs newest first, paginated', function () {
    foreach (range(1, 22) as $i) {
        ScrapeRun::factory()->create([
            'started_at' => now()->subDays(22 - $i),
            'finished_at' => now()->subDays(22 - $i)->addMinutes(5),
            'status' => ScrapeRunStatus::Finished,
            'signals_found' => $i,
        ]);
    }

    $this->get(route('runs.index'))->assertInertia(fn (Assert $page) => $page
        ->component('runs/index')
        ->where('runs.total', 22)
        ->has('runs.data', 20)
        ->where('runs.data.0.signalsFound', 22)
        ->where('runs.data.0.durationSeconds', 300)
        ->where('runs.lastPage', 2));
});

it('shows 14 days of API usage per service with errors', function () {
    ApiUsage::factory()->count(3)->create(['service' => ExternalService::Brave, 'date' => '2026-07-15']);
    ApiUsage::factory()->create(['service' => ExternalService::Brave, 'date' => '2026-07-15', 'status_code' => 429]);
    ApiUsage::factory()->create(['service' => ExternalService::MillionVerifier, 'date' => '2026-07-10', 'cost_units' => 1]);
    ApiUsage::factory()->create(['service' => ExternalService::Reed, 'date' => '2026-06-01']); // too old

    $this->get(route('runs.index'))->assertInertia(fn (Assert $page) => $page
        ->has('apiUsage.days', 14)
        ->has('apiUsage.services', count(ExternalService::cases()))
        ->where('apiUsage.days.0.date', '2026-07-15')
        ->where('apiUsage.days.0.services.brave', ['calls' => 4, 'cost' => 4, 'errors' => 1])
        ->where('apiUsage.days.5.date', '2026-07-10')
        ->where('apiUsage.days.5.services.millionverifier.calls', 1)
        ->where('apiUsage.days.13.date', '2026-07-02'));
});

it('lists failed jobs and retries or removes one', function () {
    $uuid = failedJob();

    $this->get(route('runs.index'))->assertInertia(fn (Assert $page) => $page
        ->where('failedJobCount', 1)
        ->where('failedJobs.0.uuid', $uuid)
        ->where('failedJobs.0.job', 'CrawlCompanyWebsiteJob')
        ->where('failedJobs.0.queue', 'web')
        ->where('failedJobs.0.error', 'RuntimeException: Connection timed out in /app/Services/Crawler.php:10'));

    $this->post(route('runs.failed.retry', $uuid))->assertRedirect()->assertInertiaFlash('toast.message', 'Job queued again');
    expect(DB::table('failed_jobs')->count())->toBe(0);

    $other = failedJob();
    $this->delete(route('runs.failed.forget', $other))->assertInertiaFlash('toast.message', 'Failed job removed');
    expect(DB::table('failed_jobs')->count())->toBe(0);

    $this->post(route('runs.failed.retry', 'not-a-uuid'))->assertNotFound();
    $this->delete(route('runs.failed.forget', (string) Str::uuid()))->assertNotFound();
});

it('runs discovery now as a manual run', function () {
    config(['services.reed.key' => 'test-reed-key', 'services.adzuna.app_id' => null, 'services.adzuna.app_key' => null]);

    $this->post(route('runs.discover'))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect(ScrapeRun::query()->sole())
        ->trigger->toBe(RunTrigger::Manual)
        ->status->toBe(ScrapeRunStatus::Running);
    Queue::assertPushed(FetchReedJobsJob::class);

    // A second click while that run is going starts nothing.
    $this->post(route('runs.discover'))->assertInertiaFlash('toast.type', 'warning');
    expect(ScrapeRun::count())->toBe(1);
});

it('says why discovery could not start', function () {
    config(['services.reed.key' => null, 'services.adzuna.app_id' => null, 'services.adzuna.app_key' => null]);

    $this->post(route('runs.discover'))
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', 'Nothing to search: check keys, search.keywords and search.countries. Skipped: reed: REED_API_KEY not set; adzuna: ADZUNA_APP_ID / ADZUNA_APP_KEY not set.');
});
