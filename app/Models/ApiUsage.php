<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExternalService;
use Database\Factories\ApiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One outbound call to an external service. Drives daily caps and the Runs page.
 *
 * @property int $id
 * @property ExternalService $service
 * @property string $endpoint
 * @property int|null $status_code
 * @property int|null $duration_ms
 * @property string $cost_units
 * @property Carbon $date
 * @property Carbon|null $created_at
 */
#[Fillable(['service', 'endpoint', 'status_code', 'duration_ms', 'cost_units', 'date'])]
class ApiUsage extends Model
{
    /** @use HasFactory<ApiUsageFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'service' => ExternalService::class,
            'status_code' => 'integer',
            'duration_ms' => 'integer',
            'cost_units' => 'decimal:2',
            'date' => 'date',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('date', '<', now()->subDays(180)->toDateString());
    }
}
