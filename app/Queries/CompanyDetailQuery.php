<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\RetryPipelineStepAction;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\PipelineEvent;
use App\Models\Signal;

/**
 * Everything the lead page shows (docs/07 §3). Local queries only.
 */
final class CompanyDetailQuery
{
    private const COMPANIES_HOUSE_URL = 'https://find-and-update.company-information.service.gov.uk/company/';

    public function __construct(private readonly RetryPipelineStepAction $retry) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Company $company): array
    {
        $company->load([
            'founders.email',
            'contactEmail',
            'careersEmail',
            'emailAddresses' => fn ($q) => $q->orderBy('id'),
            'signals' => fn ($q) => $q->latest('posted_at')->latest('id'),
            'pipelineEvents' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
        ]);

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'legalName' => $company->legal_name,
                'website' => $company->website,
                'domain' => $company->domain,
                'location' => $company->location,
                'country' => $company->country,
                'registeredAddress' => $this->address($company->registered_address),
                'registrationNumber' => $company->registration_number,
                'companiesHouseUrl' => $company->registry === 'companies_house' && $company->registration_number !== null
                    ? self::COMPANIES_HOUSE_URL.rawurlencode($company->registration_number)
                    : null,
                'companyStatus' => $company->company_status,
                'companyKind' => $company->company_kind,
                'incorporatedOn' => $company->incorporated_on?->toDateString(),
                'sicCodes' => $company->sic_codes ?? [],
                'accountsCategory' => $company->accounts_category,
                'type' => $company->company_type->value,
                'status' => $company->status->value,
                'rejectReason' => $company->reject_reason?->value,
                'pipelineStep' => $company->pipeline_step->value,
                'score' => $company->score === null ? null : (float) $company->score,
                'outreachStatus' => $company->outreach_status->value,
                'contactedAt' => $company->contacted_at?->toIso8601String(),
                'qualifiedAt' => $company->qualified_at?->toIso8601String(),
                'notes' => $company->notes,
                'canRetry' => $this->retry->canRetry($company),
                'contactEmail' => $company->contactEmail === null ? null : $this->email($company->contactEmail),
                'careersEmail' => $company->careersEmail === null ? null : $this->email($company->careersEmail),
            ],
            'founders' => $company->founders->map(fn ($person): array => [
                'id' => $person->id,
                'position' => $person->position,
                'name' => trim($person->first_name.' '.$person->last_name),
                'role' => $person->role->value,
                'appointedOn' => $person->appointed_on?->toDateString(),
                'source' => $person->source->value,
                'sourceUrl' => $person->source_url,
                'linkedinUrl' => $person->linkedin_url,
                'linkedinSourceUrl' => $person->linkedin_source_url,
                'email' => $person->email === null ? null : $this->email($person->email),
                'candidates' => $company->emailAddresses
                    ->where('person_id', $person->id)
                    ->map(fn (EmailAddress $e): array => $this->email($e))
                    ->values()
                    ->all(),
            ])->values()->all(),
            'scoreBreakdown' => $this->breakdown($company),
            'laravelEvidence' => $company->laravel_evidence ?? [],
            'offshoreEvidence' => $company->offshore_evidence ?? [],
            'signals' => $company->signals->map(fn (Signal $s): array => [
                'id' => $s->id,
                'title' => $s->title,
                'source' => $s->source->value,
                'employerName' => $s->employer_name,
                'location' => $s->location,
                'url' => $s->url,
                'postedAt' => $s->posted_at?->toIso8601String(),
            ])->values()->all(),
            'events' => $company->pipelineEvents->map(fn (PipelineEvent $e): array => [
                'id' => $e->id,
                'step' => $e->step->value,
                'outcome' => $e->outcome->value,
                'message' => $e->message,
                'context' => $e->context,
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Companies House address parts in postal order (MySQL JSON does not keep key order).
     *
     * @param  array<string, mixed>|null  $address
     */
    private function address(?array $address): ?string
    {
        if ($address === null) {
            return null;
        }

        $order = ['care_of', 'po_box', 'premises', 'address_line_1', 'address_line_2', 'locality', 'region', 'postal_code', 'country'];
        $parts = [];

        foreach ($order as $key) {
            if (is_string($address[$key] ?? null) && trim($address[$key]) !== '') {
                $parts[] = trim($address[$key]);
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function email(EmailAddress $email): array
    {
        return [
            'id' => $email->id,
            'email' => $email->email,
            'kind' => $email->kind->value,
            'origin' => $email->origin->value,
            'sourceUrl' => $email->source_url,
            'status' => $email->verification_status->value,
            'verifiedAt' => $email->verified_at?->toIso8601String(),
        ];
    }

    /**
     * Score lines in docs/06 order; null before the lead has been scored.
     *
     * @return array{total: float, failedGate: string|null, lines: list<array{key: string, points: float, max: float, items: array<string, float>, daysOld: int|null}>}|null
     */
    private function breakdown(Company $company): ?array
    {
        $raw = $company->score_breakdown;

        if (! is_array($raw)) {
            return null;
        }

        $lines = [];
        foreach (['laravel_evidence', 'timing', 'company_fit', 'decision_maker_reach', 'company_inboxes'] as $key) {
            $line = $raw[$key] ?? null;

            if (! is_array($line)) {
                continue;
            }

            $items = [];
            foreach (is_array($line['items'] ?? null) ? $line['items'] : [] as $item => $points) {
                $items[(string) $item] = (float) $points;
            }

            $lines[] = [
                'key' => $key,
                'points' => (float) ($line['points'] ?? 0),
                'max' => (float) ($line['max'] ?? 0),
                'items' => $items,
                'daysOld' => is_numeric($line['days_old'] ?? null) ? (int) $line['days_old'] : null,
            ];
        }

        return [
            'total' => (float) ($raw['total'] ?? $company->score ?? 0),
            'failedGate' => is_string($raw['failed_gate'] ?? null) ? $raw['failed_gate'] : null,
            'lines' => $lines,
        ];
    }
}
