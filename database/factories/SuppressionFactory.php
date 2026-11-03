<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Suppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Suppression>
 */
class SuppressionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SuppressionType::Domain,
            'value' => fake()->unique()->domainWord().'.test',
            'reason' => SuppressionReason::AlreadyContacted,
        ];
    }
}
