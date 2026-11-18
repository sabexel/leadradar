<?php

declare(strict_types=1);

namespace App\DTOs\Imports;

final readonly class OutreachImportResult
{
    /**
     * @param  array{email: string, status: list<string>}  $columns  headers used
     */
    public function __construct(
        public int $rows,
        public int $addresses,
        public int $contacted,
        public int $replied,
        public int $bounced,
        public int $unsubscribed,
        public int $notInLeadRadar,
        public int $notSent,
        public array $columns,
    ) {}

    public function summary(): string
    {
        return sprintf(
            '%d addresses read. Leads updated: %d contacted, %d replied, %d bounced, %d unsubscribed. %d not in LeadRadar (now on the do-not-contact list), %d not sent yet or failed.',
            $this->addresses,
            $this->contacted,
            $this->replied,
            $this->bounced,
            $this->unsubscribed,
            $this->notInLeadRadar,
            $this->notSent,
        );
    }
}
