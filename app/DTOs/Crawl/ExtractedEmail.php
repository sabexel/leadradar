<?php

declare(strict_types=1);

namespace App\DTOs\Crawl;

use App\Enums\EmailKind;

final readonly class ExtractedEmail
{
    public function __construct(
        public string $email,
        public EmailKind $kind,
        public string $sourceUrl,
    ) {}

    public function localPart(): string
    {
        return explode('@', $this->email)[0];
    }

    /**
     * @return array{email: string, kind: string}
     */
    public function toArray(): array
    {
        return ['email' => $this->email, 'kind' => $this->kind->value];
    }
}
