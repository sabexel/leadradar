<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataSource;
use App\Enums\PersonRole;
use App\Models\Company;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'position' => 1,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'role' => PersonRole::Director,
            'appointed_on' => fake()->dateTimeBetween('-10 years', '-1 year'),
            'resigned_on' => null,
            'country_of_residence' => 'England',
            'source' => DataSource::CompaniesHouse,
            'source_url' => 'https://api.company-information.service.gov.uk/company/00000000/officers',
        ];
    }

    public function position(int $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    public function resigned(): static
    {
        return $this->state(fn () => [
            'position' => null,
            'resigned_on' => fake()->dateTimeBetween('-1 year', 'now'),
        ]);
    }
}
