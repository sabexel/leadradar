<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExternalService;
use App\Models\ApiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiUsage>
 */
class ApiUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service' => ExternalService::CompaniesHouse,
            'endpoint' => '/search/companies',
            'status_code' => 200,
            'duration_ms' => fake()->numberBetween(50, 800),
            'cost_units' => 1,
            'date' => today(),
        ];
    }
}
