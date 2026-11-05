<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Imports\CompanyImportResult;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\Signal;
use App\Services\Dedupe\CompanyMatcher;
use App\Services\Support\CsvReader;
use App\Services\Support\SuppressionList;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Local, sync: companies from a pasted list or a CSV (Apollo exports, manual finds) → Companies with an `import`
 * signal, each sent into the pipeline (docs/07 §4). Existing companies (same domain or name) and suppressed ones are
 * skipped — nothing is spent on them (D97).
 */
final class ImportCompaniesAction
{
    public const MAX_ROWS = 500;

    public function __construct(
        private readonly CompanyMatcher $matcher,
        private readonly SuppressionList $suppressions,
        private readonly AdvanceLeadPipelineAction $advance,
        private readonly CsvReader $csv,
    ) {}

    /**
     * One company per line: a name, a domain/URL, or "Name, domain" (comma or tab).
     */
    public function fromText(string $text): CompanyImportResult
    {
        $entries = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', preg_split('/\t|,(?=[^,]*$)/', $line) ?: [$line]);

            if (count($parts) === 2 && $this->looksLikeWebsite($parts[1])) {
                $entries[] = ['name' => $parts[0], 'website' => $parts[1]];
            } elseif ($this->looksLikeWebsite($line)) {
                $entries[] = ['name' => null, 'website' => $line];
            } else {
                $entries[] = ['name' => $line, 'website' => null];
            }
        }

        return $this->import($entries);
    }

    /**
     * @param  array{name?: string|null, website?: string|null}|null  $mapping  header names; null = detect
     */
    public function fromCsv(string $path, ?array $mapping = null): CompanyImportResult
    {
        [$headers, $rows] = $this->csv->read($path);
        $columns = $mapping === null ? $this->detect($headers) : [
            'name' => $this->index($headers, $mapping['name'] ?? null),
            'website' => $this->index($headers, $mapping['website'] ?? null),
        ];

        if ($columns['name'] === null && $columns['website'] === null) {
            throw new InvalidArgumentException('No company name or website column found — pick the columns.');
        }

        $entries = array_map(fn (array $row): array => [
            'name' => $columns['name'] === null ? null : ($row[$columns['name']] ?? null),
            'website' => $columns['website'] === null ? null : ($row[$columns['website']] ?? null),
        ], $rows);

        return $this->import($entries);
    }

    /**
     * @return array{headers: list<string>, rows: int, detected: array{name: string|null, website: string|null}}
     */
    public function preview(string $path): array
    {
        [$headers, $rows] = $this->csv->read($path);
        $columns = $this->detect($headers);

        return [
            'headers' => $headers,
            'rows' => count($rows),
            'detected' => [
                'name' => $columns['name'] === null ? null : $headers[$columns['name']],
                'website' => $columns['website'] === null ? null : $headers[$columns['website']],
            ],
        ];
    }

    /**
     * @param  list<array{name: string|null, website: string|null}>  $entries
     */
    private function import(array $entries): CompanyImportResult
    {
        if (count($entries) > self::MAX_ROWS) {
            throw new InvalidArgumentException('At most '.self::MAX_ROWS.' companies per import.');
        }

        $created = [];
        $existing = 0;
        $suppressed = 0;
        $invalid = 0;
        $seen = [];

        foreach ($entries as $entry) {
            $domain = $this->matcher->domainOf($this->clean($entry['website']));
            $name = Str::squish($this->clean($entry['name']) ?? '');

            if ($name === '' && $domain !== null) {
                $name = $this->nameFromDomain($domain);
            }

            $normalised = $this->matcher->normalise($name);

            if ($normalised === '') {
                $invalid++;

                continue;
            }

            $key = $domain ?? "name:{$normalised}";
            if (isset($seen[$key])) {
                $existing++;

                continue;
            }
            $seen[$key] = true;

            if ($this->suppressions->isSuppressed(new Company(['domain' => $domain]))) {
                $suppressed++;

                continue;
            }

            $company = Cache::lock("ingest-company:{$normalised}", 10)->block(5, fn () => DB::transaction(function () use ($name, $normalised, $domain): ?Company {
                $match = Company::query()
                    ->where(fn ($q) => $domain === null
                        ? $q->where('normalised_name', $normalised)
                        : $q->where('domain', $domain)->orWhere('normalised_name', $normalised))
                    ->exists();

                if ($match) {
                    return null;
                }

                $company = Company::create([
                    'name' => $name,
                    'normalised_name' => $normalised,
                    'website' => $domain === null ? null : "https://{$domain}",
                    'domain' => $domain,
                    'country' => 'GB',
                    'status' => LeadStatus::Discovered,
                    'pipeline_step' => PipelineStep::RegistrySearch,
                ]);

                Signal::create([
                    'company_id' => $company->id,
                    'source' => SignalSource::Import,
                    'external_id' => substr(sha1($domain ?? $normalised), 0, 40),
                    'title' => 'Imported',
                    'employer_name' => $name,
                    'url' => $company->website,
                    'posted_at' => now(),
                    'raw' => ['name' => $name, 'domain' => $domain],
                ]);

                return $company;
            }));

            if ($company === null) {
                $existing++;

                continue;
            }

            $created[] = $company;
        }

        foreach ($created as $company) {
            $company->recordEvent(PipelineStep::RegistrySearch, PipelineOutcome::Ok, 'Imported from a list', ['domain' => $company->domain]);
            $this->advance->run($company);
        }

        return new CompanyImportResult(
            rows: count($entries),
            created: count($created),
            existing: $existing,
            suppressed: $suppressed,
            invalid: $invalid,
        );
    }

    /**
     * @param  list<string>  $headers
     * @return array{name: int|null, website: int|null}
     */
    private function detect(array $headers): array
    {
        $normalised = array_map(fn (string $h): string => Str::lower(Str::squish($h)), $headers);
        $find = function (string $pattern, string $exclude) use ($normalised): ?int {
            foreach ($normalised as $i => $header) {
                if (preg_match($pattern, $header) === 1 && preg_match($exclude, $header) !== 1) {
                    return $i;
                }
            }

            return null;
        };

        return [
            // "Company Name", "Company", "Account Name", "Organisation" — not "First Name" or "Founder 1 Last Name".
            'name' => $find('/\b(?:company name|company|account name|organi[sz]ation|business name|name)\b/', '/first|last|founder|person|contact|house|number|\bno\b|email|website|linkedin/'),
            'website' => $find('/\b(?:website|web site|domain|url)\b/', '/signal|linkedin|job|advert|\bad\b/'),
        ];
    }

    /**
     * @param  list<string>  $headers
     */
    private function index(array $headers, ?string $name): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        foreach ($headers as $i => $header) {
            if (Str::lower(Str::squish($header)) === Str::lower(Str::squish($name))) {
                return $i;
            }
        }

        throw new InvalidArgumentException("Column \"{$name}\" not found in the CSV header.");
    }

    private function looksLikeWebsite(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && ! str_contains($value, ' ')
            && preg_match('#^(?:https?://)?(?:www\.)?[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,24}(?:[/?\#].*)?$#i', $value) === 1;
    }

    /**
     * "example-digital.co.uk" → "Example Digital" — a starting point for the registry search.
     */
    private function nameFromDomain(string $domain): string
    {
        $label = explode('.', $domain)[0];

        return Str::of($label)->replace(['-', '_'], ' ')->squish()->title()->toString();
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim(str_replace("\u{00A0}", ' ', $value), " \t\"'");

        return $value === '' ? null : $value;
    }
}
