<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Imports\SuppressionImportResult;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineOutcome;
use App\Enums\RejectReason;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\Suppression;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Support\CsvReader;
use App\Services\Support\SuppressionList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Local, sync: turn the owner's outreach sheet (CSV export) into suppressions, so nobody already contacted is
 * ever enriched or contacted again (docs/07 §4, docs/09). Leads already in LeadRadar that match are suppressed.
 *
 * Columns are detected from the sheet's headers (docs/07 export format) or given explicitly.
 */
final class ImportContactedCsvAction
{
    /**
     * Free-mail domains never become a domain suppression (an email on them is still suppressed by address).
     */
    private const FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'hotmail.co.uk', 'live.com', 'live.co.uk',
        'msn.com', 'yahoo.com', 'yahoo.co.uk', 'icloud.com', 'me.com', 'aol.com', 'protonmail.com', 'proton.me',
        'btinternet.com', 'sky.com', 'virginmedia.com', 'talktalk.net', 'mail.com', 'gmx.com', 'zoho.com',
    ];

    public function __construct(
        private readonly CompanyMatcher $matcher,
        private readonly SuppressionList $suppressions,
        private readonly CsvReader $csv,
    ) {}

    /**
     * @param  array{website?: list<string>, email?: list<string>, company_number?: list<string>}|null  $mapping
     *                                                                                                            header names per kind; null = detect from the headers
     */
    public function run(string $path, ?array $mapping = null, SuppressionReason $reason = SuppressionReason::AlreadyContacted): SuppressionImportResult
    {
        [$headers, $rows] = $this->csv->read($path);
        $columns = $this->columns($headers, $mapping);

        $values = ['domain' => [], 'email' => [], 'company_number' => []];

        foreach ($rows as $row) {
            foreach ($columns['website'] as $index) {
                $domain = $this->matcher->domainOf($this->clean($row[$index] ?? ''));

                if ($domain !== null) {
                    $values['domain'][$domain] = true;
                }
            }

            foreach ($columns['email'] as $index) {
                foreach ($this->emails($row[$index] ?? '') as $email) {
                    $values['email'][$email] = true;
                    $domain = explode('@', $email)[1];

                    if (! in_array($domain, self::FREE_MAIL, true)) {
                        $values['domain'][$domain] = true;
                    }
                }
            }

            foreach ($columns['company_number'] as $index) {
                $number = $this->companyNumber($row[$index] ?? '');

                if ($number !== null) {
                    $values['company_number'][$number] = true;
                }
            }
        }

        $created = ['domain' => 0, 'email' => 0, 'company_number' => 0];
        $existing = 0;

        DB::transaction(function () use ($values, $reason, &$created, &$existing): void {
            foreach ([
                'domain' => SuppressionType::Domain,
                'email' => SuppressionType::Email,
                'company_number' => SuppressionType::CompanyNumber,
            ] as $key => $type) {
                foreach (array_keys($values[$key]) as $value) {
                    $row = Suppression::query()->firstOrCreate(['type' => $type, 'value' => (string) $value], ['reason' => $reason]);
                    $row->wasRecentlyCreated ? $created[$key]++ : $existing++;
                }
            }
        });

        return new SuppressionImportResult(
            rows: count($rows),
            domains: $created['domain'],
            emails: $created['email'],
            companyNumbers: $created['company_number'],
            alreadySuppressed: $existing,
            leadsSuppressed: $this->suppressExistingLeads($reason),
            columns: [
                'website' => array_map(fn (int $i): string => $headers[$i], $columns['website']),
                'email' => array_map(fn (int $i): string => $headers[$i], $columns['email']),
                'company_number' => array_map(fn (int $i): string => $headers[$i], $columns['company_number']),
            ],
        );
    }

    /**
     * For the column-mapping UI (docs/07 §4): the file's headers and the columns detection would use.
     *
     * @return array{headers: list<string>, rows: int, detected: array{website: list<string>, email: list<string>, company_number: list<string>}}
     */
    public function preview(string $path): array
    {
        [$headers, $rows] = $this->csv->read($path);

        try {
            $columns = $this->columns($headers, null);
        } catch (InvalidArgumentException) {
            $columns = ['website' => [], 'email' => [], 'company_number' => []];
        }

        return [
            'headers' => $headers,
            'rows' => count($rows),
            'detected' => [
                'website' => array_map(fn (int $i): string => $headers[$i], $columns['website']),
                'email' => array_map(fn (int $i): string => $headers[$i], $columns['email']),
                'company_number' => array_map(fn (int $i): string => $headers[$i], $columns['company_number']),
            ],
        ];
    }

    /**
     * Leads already in LeadRadar that are now on the list stop where they are.
     */
    private function suppressExistingLeads(SuppressionReason $reason): int
    {
        $count = 0;

        Company::query()
            ->where('status', '!=', LeadStatus::Suppressed)
            ->with('emailAddresses')
            ->chunkById(200, function ($companies) use ($reason, &$count): void {
                foreach ($companies as $company) {
                    if (! $this->suppressions->isSuppressed($company)) {
                        continue;
                    }

                    $company->update([
                        'status' => LeadStatus::Suppressed,
                        'reject_reason' => RejectReason::Suppressed,
                        'outreach_status' => $reason === SuppressionReason::AlreadyContacted && $company->outreach_status === OutreachStatus::NotContacted
                            ? OutreachStatus::Contacted
                            : $company->outreach_status,
                    ]);
                    $company->recordEvent($company->pipeline_step, PipelineOutcome::Rejected, 'Suppressed by import', ['reason' => $reason->value]);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  list<string>  $headers
     * @param  array{website?: list<string>, email?: list<string>, company_number?: list<string>}|null  $mapping
     * @return array{website: list<int>, email: list<int>, company_number: list<int>}
     */
    private function columns(array $headers, ?array $mapping): array
    {
        $normalised = array_map(fn (string $h): string => Str::lower(Str::squish($h)), $headers);

        if ($mapping !== null) {
            $pick = function (array $names) use ($normalised): array {
                $indexes = [];

                foreach ($names as $name) {
                    $index = array_search(Str::lower(Str::squish($name)), $normalised, true);

                    if ($index === false) {
                        throw new InvalidArgumentException("Column \"{$name}\" not found in the CSV header.");
                    }

                    $indexes[] = (int) $index;
                }

                return $indexes;
            };

            $columns = [
                'website' => $pick($mapping['website'] ?? []),
                'email' => $pick($mapping['email'] ?? []),
                'company_number' => $pick($mapping['company_number'] ?? []),
            ];
        } else {
            $match = fn (string $pattern): array => array_keys(array_filter($normalised, fn (string $h): bool => preg_match($pattern, $h) === 1));

            $columns = [
                // "Website" yes; "Signal URL" (a job-board link) and "Founder 1 LinkedIn" no.
                'website' => array_values(array_filter($match('/\b(?:website|web site|domain|url)\b/'), fn (int $i): bool => preg_match('/signal|linkedin|job|advert|\bad\b/', $normalised[$i]) !== 1)),
                // "Founder 1 email" yes, "Founder 1 email test" (the verification column) no.
                'email' => array_values(array_filter($match('/\be-?mail\b/'), fn (int $i): bool => preg_match('/\btest\b|\bstatus\b/', $normalised[$i]) !== 1)),
                'company_number' => $match('/compan(?:y|ies) house|company (?:no|number|reg)|\breg(?:istration)?\.? ?(?:no|number)\b|\bcrn\b/'),
            ];
        }

        if ($columns['website'] === [] && $columns['email'] === [] && $columns['company_number'] === []) {
            throw new InvalidArgumentException('No website, email or company number column found — map the columns explicitly.');
        }

        return $columns;
    }

    /**
     * Spreadsheet residue: `="07172996"`, surrounding quotes, non-breaking spaces.
     */
    private function clean(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = (string) preg_replace('/^="?(.*?)"?$/', '$1', trim($value));

        return trim($value, " \t\"'");
    }

    /**
     * A cell may hold several addresses ("a@x.test; b@x.test").
     *
     * @return list<string>
     */
    private function emails(string $value): array
    {
        preg_match_all('/[a-z0-9][a-z0-9._%+\-]*@(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,24}/i', $this->clean($value), $m);

        return array_values(array_unique(array_map(fn (string $e): string => Str::lower($e), $m[0])));
    }

    /**
     * Companies House numbers are 8 characters; spreadsheets drop leading zeros ("7172996" → "07172996").
     */
    private function companyNumber(string $value): ?string
    {
        $value = Str::upper((string) preg_replace('/\s+/', '', $this->clean($value)));

        if (preg_match('/^\d{1,8}$/', $value) === 1) {
            return str_pad($value, 8, '0', STR_PAD_LEFT);
        }

        return preg_match('/^[A-Z]{2}\d{6}$/', $value) === 1 ? $value : null; // SC123456, NI012345, OC300000…
    }
}
