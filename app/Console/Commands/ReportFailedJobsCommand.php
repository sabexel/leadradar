<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\FailedJobsSummary;
use App\Models\User;
use App\Queries\RunsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('leadradar:report-failed-jobs')]
#[Description('Email a summary of the queue jobs that failed in the last 24 hours (nothing is sent when none failed)')]
final class ReportFailedJobsCommand extends Command
{
    public function handle(RunsQuery $runs): int
    {
        $jobs = $runs->failedJobsSince(CarbonImmutable::now()->subDay());

        if ($jobs === []) {
            $this->info('No failed jobs in the last 24 hours.');

            return self::SUCCESS;
        }

        $recipient = config('leadradar.alert_email') ?: User::query()->orderBy('id')->value('email');

        if (! is_string($recipient) || $recipient === '') {
            $this->warn(count($jobs).' failed jobs, but there is no LEADRADAR_ALERT_EMAIL and no user to send the summary to.');

            return self::SUCCESS;
        }

        Mail::to($recipient)->send(new FailedJobsSummary($jobs));

        $this->info('Sent the summary of '.count($jobs).' failed jobs.');

        return self::SUCCESS;
    }
}
