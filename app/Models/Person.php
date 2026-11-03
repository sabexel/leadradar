<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataSource;
use App\Enums\PersonRole;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A founder/director. Never store date of birth (docs/09).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $position
 * @property string $first_name
 * @property string $last_name
 * @property string|null $preferred_name
 * @property PersonRole $role
 * @property Carbon|null $appointed_on
 * @property Carbon|null $resigned_on
 * @property string|null $country_of_residence
 * @property string|null $linkedin_url
 * @property string|null $linkedin_source_url
 * @property int|null $email_id
 * @property DataSource $source
 * @property string|null $source_url
 * @property-read string $full_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('people')]
#[Fillable([
    'company_id', 'position', 'first_name', 'last_name', 'preferred_name', 'role', 'appointed_on',
    'resigned_on', 'country_of_residence', 'linkedin_url', 'linkedin_source_url', 'email_id',
    'source', 'source_url',
])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'role' => PersonRole::class,
            'appointed_on' => 'date',
            'resigned_on' => 'date',
            'source' => DataSource::class,
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
     * The best email for this person.
     *
     * @return BelongsTo<EmailAddress, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(EmailAddress::class, 'email_id');
    }

    /**
     * Every email tried or found for this person.
     *
     * @return HasMany<EmailAddress, $this>
     */
    public function emailAddresses(): HasMany
    {
        return $this->hasMany(EmailAddress::class);
    }

    public function isActive(): bool
    {
        return $this->resigned_on === null;
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(get: fn (): string => trim($this->first_name.' '.$this->last_name));
    }
}
