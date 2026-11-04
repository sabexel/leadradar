<?php

declare(strict_types=1);

namespace App\DTOs\Verification;

use App\Enums\EmailVerificationStatus;

final readonly class EmailVerificationResult
{
    /**
     * @param  array<string, mixed>  $raw  the verifier's response, stored in `verification_raw`
     */
    public function __construct(
        public string $email,
        public EmailVerificationStatus $status,
        public ?string $subresult,
        public ?string $quality,
        public ?int $creditsLeft,
        public array $raw,
    ) {}
}
