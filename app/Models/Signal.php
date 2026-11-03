<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SignalSource;
use Database\Factories\SignalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Why we found a company (e.g. a job ad mentioning Laravel).
 *
 * @property int $id
 * @property int|null $company_id
 * @property SignalSource $source
 * @property string $external_id
 * @property string $title
 * @property string $employer_name
 * @property string|null $location
 * @property string|null $description
 * @property string|null $url
 * @property Carbon|null $posted_at
 * @property array<string, mixed> $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'company_id', 'source', 'external_id', 'title', 'employer_name', 'location',
    'description', 'url', 'posted_at', 'raw',
])]
class Signal extends Model
{
    /** @use HasFactory<SignalFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => SignalSource::class,
            'posted_at' => 'datetime',
            'raw' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
