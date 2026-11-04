<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyType;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string $normalised_name
 * @property string|null $website
 * @property string|null $domain
 * @property string $country
 * @property string|null $location
 * @property array<string, mixed>|null $registered_address
 * @property string|null $registration_number
 * @property string|null $registry
 * @property string|null $company_status
 * @property string|null $company_kind
 * @property Carbon|null $incorporated_on
 * @property list<string>|null $sic_codes
 * @property string|null $accounts_category
 * @property int|null $contact_email_id
 * @property int|null $careers_email_id
 * @property CompanyType $company_type
 * @property list<array{type: string, detail: string, url?: string|null}>|null $laravel_evidence
 * @property list<array{type: string, detail: string, url?: string|null}>|null $offshore_evidence
 * @property LeadStatus $status
 * @property PipelineStep $pipeline_step
 * @property RejectReason|null $reject_reason
 * @property string|null $score
 * @property array<string, mixed>|null $score_breakdown
 * @property OutreachStatus $outreach_status
 * @property Carbon|null $contacted_at
 * @property Carbon|null $qualified_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'legal_name', 'normalised_name', 'website', 'domain', 'country', 'location',
    'registered_address', 'registration_number', 'registry', 'company_status', 'company_kind',
    'incorporated_on', 'sic_codes', 'accounts_category', 'contact_email_id', 'careers_email_id',
    'company_type', 'laravel_evidence', 'offshore_evidence', 'status', 'pipeline_step', 'reject_reason',
    'score', 'score_breakdown', 'outreach_status', 'contacted_at', 'qualified_at', 'notes',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, Prunable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'country' => 'GB',
        'company_type' => 'unknown',
        'status' => 'discovered',
        'pipeline_step' => 'registry_search',
        'outreach_status' => 'not_contacted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registered_address' => 'array',
            'incorporated_on' => 'date',
            'sic_codes' => 'array',
            'company_type' => CompanyType::class,
            'laravel_evidence' => 'array',
            'offshore_evidence' => 'array',
            'status' => LeadStatus::class,
            'pipeline_step' => PipelineStep::class,
            'reject_reason' => RejectReason::class,
            'score' => 'decimal:1',
            'score_breakdown' => 'array',
            'outreach_status' => OutreachStatus::class,
            'contacted_at' => 'datetime',
            'qualified_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /**
     * Founder 1 and Founder 2, in order. Resigned people are never founders.
     *
     * @return HasMany<Person, $this>
     */
    public function founders(): HasMany
    {
        return $this->people()
            ->whereIn('position', [1, 2])
            ->whereNull('resigned_on')
            ->orderBy('position');
    }

    /**
     * @return HasMany<EmailAddress, $this>
     */
    public function emailAddresses(): HasMany
    {
        return $this->hasMany(EmailAddress::class);
    }

    /**
     * @return BelongsTo<EmailAddress, $this>
     */
    public function contactEmail(): BelongsTo
    {
        return $this->belongsTo(EmailAddress::class, 'contact_email_id');
    }

    /**
     * @return BelongsTo<EmailAddress, $this>
     */
    public function careersEmail(): BelongsTo
    {
        return $this->belongsTo(EmailAddress::class, 'careers_email_id');
    }

    /**
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * @return HasMany<CrawledPage, $this>
     */
    public function crawledPages(): HasMany
    {
        return $this->hasMany(CrawledPage::class);
    }

    /**
     * @return HasMany<PipelineEvent, $this>
     */
    public function pipelineEvents(): HasMany
    {
        return $this->hasMany(PipelineEvent::class);
    }

    /**
     * Write one timeline entry (docs/05: every transition writes a PipelineEvent).
     *
     * @param  array<string, mixed>|null  $context
     */
    public function recordEvent(PipelineStep $step, PipelineOutcome $outcome, ?string $message = null, ?array $context = null): PipelineEvent
    {
        return $this->pipelineEvents()->create([
            'step' => $step,
            'outcome' => $outcome,
            'message' => $message,
            'context' => $context,
        ]);
    }

    /**
     * Retention (docs/09): never-contacted companies with no activity for 12 months.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('outreach_status', OutreachStatus::NotContacted)
            ->where('updated_at', '<', now()->subMonths(12));
    }
}
