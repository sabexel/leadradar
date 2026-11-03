<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAddress>
 */
class EmailAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'person_id' => null,
            'email' => fake()->unique()->userName().'@example.test',
            'kind' => EmailKind::Contact,
            'origin' => EmailOrigin::Crawled,
            'source_url' => 'https://example.test/contact',
            'verification_status' => EmailVerificationStatus::Unverified,
        ];
    }

    public function forPerson(Person $person): static
    {
        return $this->state(fn () => [
            'company_id' => $person->company_id,
            'person_id' => $person->id,
            'kind' => EmailKind::Person,
            'origin' => EmailOrigin::Generated,
            'source_url' => null,
        ]);
    }

    public function verified(EmailVerificationStatus $status = EmailVerificationStatus::Ok): static
    {
        return $this->state(fn () => [
            'verification_status' => $status,
            'verification_raw' => ['result' => $status->value],
            'verified_at' => now(),
        ]);
    }
}
