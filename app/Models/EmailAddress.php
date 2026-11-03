<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use Database\Factories\EmailAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $person_id
 * @property string $email
 * @property EmailKind $kind
 * @property EmailOrigin $origin
 * @property string|null $source_url
 * @property EmailVerificationStatus $verification_status
 * @property array<string, mixed>|null $verification_raw
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'company_id', 'person_id', 'email', 'kind', 'origin', 'source_url',
    'verification_status', 'verification_raw', 'verified_at',
])]
class EmailAddress extends Model
{
    /** @use HasFactory<EmailAddressFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'verification_status' => 'unverified',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EmailKind::class,
            'origin' => EmailOrigin::class,
            'verification_status' => EmailVerificationStatus::class,
            'verification_raw' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Emails are always stored lowercased.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
