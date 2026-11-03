<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyType;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fake companies only — never real prospects (docs/08, docs/09).
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $base = fake()->unique()->lastName().' '.fake()->randomElement(['Digital', 'Studio', 'Labs', 'Web', 'Software']);
        $domain = Str::slug($base).'.test';

        return [
            'name' => $base.' Ltd',
            'legal_name' => Str::upper($base).' LTD',
            'normalised_name' => Str::of($base)->lower()->squish()->toString(),
            'website' => 'https://'.$domain,
            'domain' => $domain,
            'country' => 'GB',
            'location' => fake()->city(),
            'registered_address' => [
                'address_line_1' => fake()->streetAddress(),
                'locality' => fake()->city(),
                'postal_code' => fake()->postcode(),
                'country' => 'United Kingdom',
            ],
            'registration_number' => sprintf('%08d', fake()->unique()->numberBetween(1, 99999999)),
            'registry' => 'companies_house',
            'company_status' => 'active',
            'company_kind' => 'ltd',
            'incorporated_on' => fake()->dateTimeBetween('-15 years', '-1 year'),
            'sic_codes' => ['62012'],
            'accounts_category' => fake()->randomElement(['micro-entity', 'small']),
            'company_type' => CompanyType::Agency,
            'status' => LeadStatus::Discovered,
            'pipeline_step' => PipelineStep::RegistrySearch,
        ];
    }

    public function qualified(float $score = 7.5): static
    {
        return $this->state(fn () => [
            'status' => LeadStatus::Qualified,
            'pipeline_step' => PipelineStep::Done,
            'score' => $score,
            'qualified_at' => now(),
        ]);
    }

    public function rejected(RejectReason $reason = RejectReason::BelowThreshold): static
    {
        return $this->state(fn () => [
            'status' => LeadStatus::Rejected,
            'pipeline_step' => PipelineStep::Done,
            'reject_reason' => $reason,
        ]);
    }

    /**
     * Freshly ingested from a signal: nothing known beyond the name.
     */
    public function discovered(): static
    {
        return $this->state(fn () => [
            'legal_name' => null,
            'website' => null,
            'domain' => null,
            'registered_address' => null,
            'registration_number' => null,
            'registry' => null,
            'company_status' => null,
            'company_kind' => null,
            'incorporated_on' => null,
            'sic_codes' => null,
            'accounts_category' => null,
            'company_type' => CompanyType::Unknown,
        ]);
    }
}
