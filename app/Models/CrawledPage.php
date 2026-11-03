<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageType;
use Database\Factories\CrawledPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Facts extracted from one crawled page. Full HTML is never stored.
 *
 * @property int $id
 * @property int $company_id
 * @property string $url
 * @property int|null $http_status
 * @property Carbon|null $fetched_at
 * @property string|null $content_hash
 * @property PageType $page_type
 * @property array<string, mixed>|null $extracted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['company_id', 'url', 'http_status', 'fetched_at', 'content_hash', 'page_type', 'extracted'])]
class CrawledPage extends Model
{
    /** @use HasFactory<CrawledPageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'fetched_at' => 'datetime',
            'page_type' => PageType::class,
            'extracted' => 'array',
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
