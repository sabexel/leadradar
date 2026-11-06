<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Daily summary of queue jobs that failed in the last 24 hours (docs/08 "Monitoring"). Sent synchronously by
 * `leadradar:report-failed-jobs` — never queued, so a broken queue cannot swallow its own alert.
 */
final class FailedJobsSummary extends Mailable
{
    use Queueable;

    public const LISTED = 20;

    /**
     * @param  list<array{uuid: string, job: string, queue: string, error: string, failedAt: string}>  $jobs  newest first
     */
    public function __construct(public readonly array $jobs) {}

    public function envelope(): Envelope
    {
        $count = count($this->jobs);

        return new Envelope(subject: 'LeadRadar: '.$count.' failed '.($count === 1 ? 'job' : 'jobs').' in the last 24 hours');
    }

    public function content(): Content
    {
        $byJob = collect($this->jobs)->countBy('job')->sortDesc()->all();

        return new Content(markdown: 'mail.failed-jobs-summary', with: [
            'total' => count($this->jobs),
            'byJob' => $byJob,
            'listed' => array_slice($this->jobs, 0, self::LISTED),
            'runsUrl' => route('runs.index'),
        ]);
    }
}
