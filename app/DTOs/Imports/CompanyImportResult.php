<?php

declare(strict_types=1);

namespace App\DTOs\Imports;

/**
 * Outcome of a company list / CSV import (docs/07 §4).
 */
final readonly class CompanyImportResult
{
    public function __construct(
        public int $rows,
        public int $created,
        public int $existing,
        public int $suppressed,
        public int $invalid,
    ) {}

    public function summary(): string
    {
        $parts = ["{$this->created} new ".($this->created === 1 ? 'company' : 'companies').' sent into the pipeline'];

        if ($this->existing > 0) {
            $parts[] = "{$this->existing} already known";
        }

        if ($this->suppressed > 0) {
            $parts[] = "{$this->suppressed} on the do-not-contact list";
        }

        if ($this->invalid > 0) {
            $parts[] = "{$this->invalid} unreadable";
        }

        return implode(' · ', $parts);
    }
}
