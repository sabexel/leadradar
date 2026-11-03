<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use Database\Factories\SuppressionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A domain, email or company number that must never be enriched or contacted.
 *
 * @property int $id
 * @property SuppressionType $type
 * @property string $value
 * @property SuppressionReason $reason
 * @property Carbon|null $created_at
 */
#[Fillable(['type', 'value', 'reason'])]
class Suppression extends Model
{
    /** @use HasFactory<SuppressionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SuppressionType::class,
            'reason' => SuppressionReason::class,
        ];
    }

    /**
     * Values are matched case-insensitively, so store them lowercased.
     *
     * @return Attribute<string, string>
     */
    protected function value(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }
}
