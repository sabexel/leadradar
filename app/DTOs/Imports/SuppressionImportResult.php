<?php

declare(strict_types=1);

namespace App\DTOs\Imports;

final readonly class SuppressionImportResult
{
    /**
     * @param  array{website: list<string>, email: list<string>, company_number: list<string>}  $columns  headers used
     */
    public function __construct(
        public int $rows,
        public int $domains,
        public int $emails,
        public int $companyNumbers,
        public int $alreadySuppressed,
        public int $leadsSuppressed,
        public array $columns,
    ) {}

    public function created(): int
    {
        return $this->domains + $this->emails + $this->companyNumbers;
    }
}
