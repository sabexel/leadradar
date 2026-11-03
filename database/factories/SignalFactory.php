<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SignalSource;
use App\Models\Signal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = (string) fake()->unique()->numberBetween(10000000, 99999999);

        return [
            'company_id' => null,
            'source' => SignalSource::Reed,
            'external_id' => $externalId,
            'title' => fake()->randomElement(['Laravel Developer', 'Senior PHP / Laravel Engineer', 'Full Stack Laravel Developer']),
            'employer_name' => fake()->lastName().' Digital Ltd',
            'location' => fake()->city(),
            'description' => 'We are looking for a Laravel developer to join our small team.',
            'url' => 'https://jobs.example.test/'.$externalId,
            'posted_at' => fake()->dateTimeBetween('-14 days', 'now'),
            'raw' => ['jobId' => $externalId],
        ];
    }
}
