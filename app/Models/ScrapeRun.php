<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RunTrigger;
use App\Enums\ScrapeRunStatus;
use Database\Factories\ScrapeRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One discovery run (leadradar:discover).
 *
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property RunTrigger $trigger
 * @property int $signals_found
 * @property int $companies_created
 * @property int $qualified_count
 * @property int $rejected_count
 * @property ScrapeRunStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'started_at', 'finished_at', 'trigger', 'signals_found', 'companies_created',
    'qualified_count', 'rejected_count', 'status',
])]
class ScrapeRun extends Model
{
    /** @use HasFactory<ScrapeRunFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'trigger' => 'schedule',
        'status' => 'running',
        'signals_found' => 0,
        'companies_created' => 0,
        'qualified_count' => 0,
        'rejected_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'trigger' => RunTrigger::class,
            'signals_found' => 'integer',
            'companies_created' => 'integer',
            'qualified_count' => 'integer',
            'rejected_count' => 'integer',
            'status' => ScrapeRunStatus::class,
        ];
    }
}
