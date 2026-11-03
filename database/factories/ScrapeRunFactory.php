<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RunTrigger;
use App\Enums\ScrapeRunStatus;
use App\Models\ScrapeRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeRun>
 */
class ScrapeRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'started_at' => now(),
            'finished_at' => null,
            'trigger' => RunTrigger::Schedule,
            'status' => ScrapeRunStatus::Running,
        ];
    }

    public function finished(): static
    {
        return $this->state(fn () => [
            'finished_at' => now(),
            'status' => ScrapeRunStatus::Finished,
        ]);
    }
}
