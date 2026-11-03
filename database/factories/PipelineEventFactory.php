<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Models\PipelineEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineEvent>
 */
class PipelineEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'step' => PipelineStep::RegistrySearch,
            'outcome' => PipelineOutcome::Ok,
            'message' => null,
            'context' => null,
        ];
    }
}
