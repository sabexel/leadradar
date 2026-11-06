<?php

declare(strict_types=1);

use App\Mail\FailedJobsSummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-06 07:00:00', 'UTC'));
    Mail::fake();
});

function insertFailedJob(string $class, CarbonImmutable $failedAt, string $error = 'RuntimeException: Connection timed out'): void
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'web',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $class]),
        'exception' => "{$error}\nStack trace:\n#0 ...",
        'failed_at' => $failedAt,
    ]);
}

it('sends nothing when no job failed in the last 24 hours', function () {
    insertFailedJob('App\\Jobs\\Enrichment\\CrawlCompanyWebsiteJob', now()->subHours(25)->toImmutable());

    $this->artisan('leadradar:report-failed-jobs')->expectsOutputToContain('No failed jobs')->assertSuccessful();

    Mail::assertNothingSent();
});

it('emails the alert address a summary of the last 24 hours only', function () {
    config(['leadradar.alert_email' => 'alerts@example.test']);
    User::factory()->create(['email' => 'owner@example.test']);

    insertFailedJob('App\\Jobs\\Enrichment\\CrawlCompanyWebsiteJob', now()->subHours(2)->toImmutable());
    insertFailedJob('App\\Jobs\\Enrichment\\CrawlCompanyWebsiteJob', now()->subHours(3)->toImmutable());
    insertFailedJob('App\\Jobs\\Verification\\VerifyEmailJob', now()->subHour()->toImmutable(), 'ExternalServiceException: MillionVerifier answered 500');
    insertFailedJob('App\\Jobs\\Sources\\FetchReedJobsJob', now()->subDays(2)->toImmutable());

    $this->artisan('leadradar:report-failed-jobs')->expectsOutputToContain('3 failed jobs')->assertSuccessful();

    Mail::assertSent(FailedJobsSummary::class, function (FailedJobsSummary $mail) {
        $html = $mail->render();

        return $mail->hasTo('alerts@example.test')
            && ! $mail->hasTo('owner@example.test')
            && $mail->envelope()->subject === 'LeadRadar: 3 failed jobs in the last 24 hours'
            && $mail->jobs[0]['job'] === 'VerifyEmailJob' // newest first
            && str_contains($html, 'CrawlCompanyWebsiteJob')
            && str_contains($html, 'MillionVerifier answered 500')
            && str_contains($html, route('runs.index'))
            && ! str_contains($html, 'FetchReedJobsJob')
            && ! str_contains($html, 'Stack trace');
    });
});

it('falls back to the user account\'s email', function () {
    config(['leadradar.alert_email' => null]);
    User::factory()->create(['email' => 'owner@example.test']);
    insertFailedJob('App\\Jobs\\Enrichment\\CrawlCompanyWebsiteJob', now()->subHour()->toImmutable());

    $this->artisan('leadradar:report-failed-jobs')->assertSuccessful();

    Mail::assertSent(FailedJobsSummary::class, fn (FailedJobsSummary $mail) => $mail->hasTo('owner@example.test')
        && $mail->envelope()->subject === 'LeadRadar: 1 failed job in the last 24 hours');
});

it('lists at most 20 jobs and says how many more there are', function () {
    config(['leadradar.alert_email' => 'alerts@example.test']);

    foreach (range(1, 23) as $minutes) {
        insertFailedJob('App\\Jobs\\Enrichment\\CrawlCompanyWebsiteJob', now()->subMinutes($minutes)->toImmutable());
    }

    $this->artisan('leadradar:report-failed-jobs')->assertSuccessful();

    Mail::assertSent(FailedJobsSummary::class, fn (FailedJobsSummary $mail) => str_contains($mail->render(), 'and 3 more'));
});

it('warns instead of failing when there is nobody to send to', function () {
    config(['leadradar.alert_email' => null]);
    insertFailedJob('App\\Jobs\\Enrichment\\CrawlCompanyWebsiteJob', now()->subHour()->toImmutable());

    $this->artisan('leadradar:report-failed-jobs')->expectsOutputToContain('no user to send')->assertSuccessful();

    Mail::assertNothingSent();
});
