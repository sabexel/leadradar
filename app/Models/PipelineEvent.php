<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use Database\Factories\PipelineEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property PipelineStep $step
 * @property PipelineOutcome $outcome
 * @property string|null $message
 * @property array<string, mixed>|null $context
 * @property Carbon|null $created_at
 */
#[Fillable(['company_id', 'step', 'outcome', 'message', 'context'])]
class PipelineEvent extends Model
{
    /** @use HasFactory<PipelineEventFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => PipelineStep::class,
            'outcome' => PipelineOutcome::class,
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths(12));
    }
}
