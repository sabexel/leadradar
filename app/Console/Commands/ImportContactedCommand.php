<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ImportContactedCsvAction;
use App\Enums\SuppressionReason;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('leadradar:import-contacted
    {file : CSV export of the outreach sheet}
    {--website=* : Header of a website column (repeatable; default: detect)}
    {--email=* : Header of an email column (repeatable; default: detect)}
    {--number=* : Header of a company-number column (repeatable; default: detect)}
    {--reason=already_contacted : already_contacted, opted_out, not_interested, competitor or manual}')]
#[Description('Suppress every domain, email and company number in a CSV so they are never enriched or contacted')]
final class ImportContactedCommand extends Command
{
    public function handle(ImportContactedCsvAction $import): int
    {
        $reason = SuppressionReason::tryFrom((string) $this->option('reason'));

        if ($reason === null) {
            $this->error('Unknown --reason.');

            return self::INVALID;
        }

        $mapping = null;

        if ($this->option('website') !== [] || $this->option('email') !== [] || $this->option('number') !== []) {
            $mapping = [
                'website' => array_values((array) $this->option('website')),
                'email' => array_values((array) $this->option('email')),
                'company_number' => array_values((array) $this->option('number')),
            ];
        }

        try {
            $result = $import->run((string) $this->argument('file'), $mapping, $reason);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result->rows} rows read. New suppressions: {$result->domains} domains, {$result->emails} emails, {$result->companyNumbers} company numbers ({$result->alreadySuppressed} already on the list).");
        $this->line("Existing leads suppressed: {$result->leadsSuppressed}.");

        foreach ($result->columns as $kind => $headers) {
            $this->line("  {$kind} columns: ".($headers === [] ? '—' : implode(', ', $headers)));
        }

        return self::SUCCESS;
    }
}
