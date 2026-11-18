<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Imports\OutreachImportResult;
use App\Enums\EmailVerificationStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineOutcome;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Suppression;
use App\Services\Support\CsvReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Local, sync: read a Mailmeteor export (campaign "Emails" CSV, or the Google Sheet with its "Merge status"
 * columns) and bring the outcomes back into LeadRadar (D105). Mailmeteor has no public API.
 *
 * - sent / opened / clicked → lead outreach "contacted" (never moved back)
 * - replied → "replied" (a lead already marked not interested or client is left alone)
 * - bounced → that address is marked invalid, the founder's best address is re-picked, finished leads rescored
 * - unsubscribed → the address goes on the do-not-contact list and the lead becomes "not interested"
 * Addresses that match no lead go on the do-not-contact list, so a contacted company never comes back as a lead.
 */
final class ImportOutreachResultsAction
{
    private const SENT = 'sent';

    private const REPLIED = 'replied';

    private const BOUNCED = 'bounced';

    private const UNSUBSCRIBED = 'unsubscribed';

    private const NOT_SENT = 'not_sent';

    public function __construct(
        private readonly CsvReader $csv,
        private readonly UpdateOutreachStatusAction $outreach,
        private readonly ApplyVerificationResultAction $verification,
        private readonly ApplyReverificationAction $reverification,
    ) {}

    /**
     * @param  array{email: string, status?: list<string>}|null  $mapping  header names; null = detect from the headers
     */
    public function run(string $path, ?array $mapping = null): OutreachImportResult
    {
        [$headers, $rows] = $this->csv->read($path);
        $columns = $this->columns($headers, $mapping);

        /** @var array<string, array<string, true>> $outcomes address → set of outcomes */
        $outcomes = [];

        foreach ($rows as $row) {
            $address = $this->email($row[$columns['email']] ?? '');

            if ($address === null) {
                continue;
            }

            $found = [];

            foreach ($columns['status'] as $index) {
                array_push($found, ...$this->outcomes($headers[$index], $row[$index] ?? ''));
            }

            // An export without any status column is a list of sent emails.
            if ($columns['status'] === []) {
                $found = [self::SENT];
            }

            $outcomes[$address] ??= [];

            foreach ($found as $outcome) {
                $outcomes[$address][$outcome] = true;
            }
        }

        $counts = ['contacted' => 0, 'replied' => 0, 'bounced' => 0, 'unsubscribed' => 0, 'unmatched' => 0, 'not_sent' => 0];

        DB::transaction(function () use ($outcomes, &$counts): void {
            foreach ($outcomes as $address => $set) {
                $this->apply((string) $address, array_keys($set), $counts);
            }
        });

        return new OutreachImportResult(
            rows: count($rows),
            addresses: count($outcomes),
            contacted: $counts['contacted'],
            replied: $counts['replied'],
            bounced: $counts['bounced'],
            unsubscribed: $counts['unsubscribed'],
            notInLeadRadar: $counts['unmatched'],
            notSent: $counts['not_sent'],
            columns: [
                'email' => $headers[$columns['email']],
                'status' => array_map(fn (int $i): string => $headers[$i], $columns['status']),
            ],
        );
    }

    /**
     * For the column-mapping UI: the file's headers and the columns detection would use.
     *
     * @return array{headers: list<string>, rows: int, detected: array{email: string|null, status: list<string>}}
     */
    public function preview(string $path): array
    {
        [$headers, $rows] = $this->csv->read($path);

        try {
            $columns = $this->columns($headers, null);
            $detected = ['email' => $headers[$columns['email']], 'status' => array_map(fn (int $i): string => $headers[$i], $columns['status'])];
        } catch (InvalidArgumentException) {
            $detected = ['email' => null, 'status' => []];
        }

        return ['headers' => $headers, 'rows' => count($rows), 'detected' => $detected];
    }

    /**
     * @param  list<string>  $outcomes
     * @param  array<string, int>  $counts
     */
    private function apply(string $address, array $outcomes, array &$counts): void
    {
        $has = fn (string $outcome): bool => in_array($outcome, $outcomes, true);
        $reached = $has(self::SENT) || $has(self::REPLIED) || $has(self::UNSUBSCRIBED);

        if (! $reached && ! $has(self::BOUNCED)) {
            $counts['not_sent']++;

            return;
        }

        $records = EmailAddress::query()->where('email', $address)->with(['company', 'person'])->get();
        $domain = Str::after($address, '@');
        $companies = $records->map(fn (EmailAddress $e): Company => $e->company)->unique('id')->values();

        if ($companies->isEmpty() && ! in_array($domain, ImportContactedCsvAction::FREE_MAIL, true)) {
            $companies = Company::query()->where('domain', $domain)->get();
        }

        if ($has(self::UNSUBSCRIBED)) {
            Suppression::query()->firstOrCreate(['type' => SuppressionType::Email, 'value' => $address], ['reason' => SuppressionReason::OptedOut]);
        }

        if ($companies->isEmpty()) {
            if ($reached) {
                $this->suppressUnmatched($address, $domain, $has(self::UNSUBSCRIBED));
                $counts['unmatched']++;
            }

            return;
        }

        foreach ($companies as $company) {
            if ($reached && $company->outreach_status === OutreachStatus::NotContacted) {
                $this->outreach->run($company, OutreachStatus::Contacted);
                $company->recordEvent($company->pipeline_step, PipelineOutcome::Ok, 'Mailmeteor: email sent');
                $counts['contacted']++;
            }

            if ($has(self::REPLIED) && in_array($company->outreach_status, [OutreachStatus::NotContacted, OutreachStatus::Contacted], true)) {
                $this->outreach->run($company, OutreachStatus::Replied);
                $company->recordEvent($company->pipeline_step, PipelineOutcome::Ok, 'Mailmeteor: replied');
                $counts['replied']++;
            }

            $bouncedRecords = $records->where('company_id', $company->id);

            if ($has(self::BOUNCED) && $bouncedRecords->isNotEmpty()) {
                $this->bounced($company, $bouncedRecords);
                $counts['bounced']++;
            }

            if ($has(self::UNSUBSCRIBED) && $company->outreach_status !== OutreachStatus::Client) {
                $this->outreach->run($company, OutreachStatus::NotInterested);
                $company->recordEvent($company->pipeline_step, PipelineOutcome::Ok, 'Mailmeteor: unsubscribed — on the do-not-contact list');
                $counts['unsubscribed']++;
            }
        }
    }

    /**
     * @param  Collection<int, EmailAddress>  $records  the bounced address's rows for this company
     */
    private function bounced(Company $company, Collection $records): void
    {
        foreach ($records as $email) {
            $email->update([
                'verification_status' => EmailVerificationStatus::Invalid,
                'verified_at' => now(),
                'verification_raw' => ['result' => 'invalid', 'subresult' => 'bounced', 'source' => 'mailmeteor'],
            ]);

            if ($email->person !== null) {
                $best = $this->verification->bestFor($company->emailAddresses()->orderBy('id')->get(), $email->person);
                $email->person->update(['email_id' => $best?->id]);
            }

            $company->recordEvent($company->pipeline_step, PipelineOutcome::Ok, "Mailmeteor: {$email->email} bounced — marked invalid");
        }

        $this->reverification->rescoreIfFinished($company);
    }

    private function suppressUnmatched(string $address, string $domain, bool $unsubscribed): void
    {
        $reason = $unsubscribed ? SuppressionReason::OptedOut : SuppressionReason::AlreadyContacted;

        Suppression::query()->firstOrCreate(['type' => SuppressionType::Email, 'value' => $address], ['reason' => $reason]);

        if (! in_array($domain, ImportContactedCsvAction::FREE_MAIL, true)) {
            Suppression::query()->firstOrCreate(['type' => SuppressionType::Domain, 'value' => $domain], ['reason' => $reason]);
        }
    }

    /**
     * What a status cell says. A column named after an event ("Replied", "Bounced") counts when the cell is filled
     * (a date, "yes", "true"); any other status column is read for its words ("Email sent", "Replied", "Bounced").
     *
     * @return list<string>
     */
    private function outcomes(string $header, string $cell): array
    {
        $cell = Str::lower(Str::squish($cell));

        if ($cell === '') {
            return [];
        }

        $named = $this->eventIn(Str::lower($header));

        if ($named !== null) {
            return in_array($cell, ['no', 'n', 'false', '0', '-', 'none'], true) ? [] : [$named];
        }

        $found = [];

        foreach ([self::UNSUBSCRIBED, self::BOUNCED, self::REPLIED] as $event) {
            if (preg_match($this->pattern($event), $cell) === 1) {
                $found[] = $event;
            }
        }

        if ($found !== []) {
            return $found;
        }

        if (preg_match('/fail|error|schedul|draft|pending|queued|cancel|not sent|unsent/', $cell) === 1) {
            return [self::NOT_SENT];
        }

        return preg_match('/sent|deliver|open|click|follow/', $cell) === 1 ? [self::SENT] : [];
    }

    private function eventIn(string $text): ?string
    {
        foreach ([self::UNSUBSCRIBED, self::BOUNCED, self::REPLIED] as $event) {
            if (preg_match($this->pattern($event), $text) === 1) {
                return $event;
            }
        }

        return preg_match('/^(?:opened|clicked|sent|delivered)(?: at| on| date)?$/', $text) === 1 ? self::SENT : null;
    }

    private function pattern(string $event): string
    {
        return match ($event) {
            self::UNSUBSCRIBED => '/unsubscri|opt(?:ed)?[\s-]?out/',
            self::BOUNCED => '/bounce/',
            default => '/repl(?:y|ied)|respond/',
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  array{email: string, status?: list<string>}|null  $mapping
     * @return array{email: int, status: list<int>}
     */
    private function columns(array $headers, ?array $mapping): array
    {
        $normalised = array_map(fn (string $h): string => Str::lower(Str::squish($h)), $headers);
        $find = function (string $name) use ($normalised): int {
            $index = array_search(Str::lower(Str::squish($name)), $normalised, true);

            return $index === false
                ? throw new InvalidArgumentException("Column \"{$name}\" not found in the CSV header.")
                : (int) $index;
        };

        if ($mapping !== null) {
            return [
                'email' => $find($mapping['email']),
                'status' => array_map($find, $mapping['status'] ?? []),
            ];
        }

        $email = null;

        foreach ($normalised as $i => $header) {
            if (preg_match('/\be-?mail\b|\brecipient\b|^to$/', $header) === 1 && preg_match('/status|event|test|sender|from|subject|template/', $header) !== 1) {
                $email = $i;

                break;
            }
        }

        if ($email === null) {
            throw new InvalidArgumentException('No email column found — choose it explicitly.');
        }

        $status = array_keys(array_filter($normalised, fn (string $h, int $i): bool => $i !== $email
            && preg_match('/status|\bevent|repl|bounce|unsubscri|opt(?:ed)?[\s-]?out|^(?:opened|clicked|sent|delivered)\b/', $h) === 1, ARRAY_FILTER_USE_BOTH));

        return ['email' => $email, 'status' => $status];
    }

    private function email(string $value): ?string
    {
        return preg_match('/[a-z0-9][a-z0-9._%+\-]*@(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,24}/i', $value, $m) === 1
            ? Str::lower($m[0])
            : null;
    }
}
