<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CompanyType;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Leads table (docs/07 §2): filters from the query string, sort, pagination. Local queries only.
 * Unknown or malformed filter values are ignored, never an error (a bookmarked URL must keep working).
 */
final class CompanyIndexQuery
{
    public const SORTS = ['score', 'qualified_at', 'name'];

    public const PER_PAGE = [25, 50, 100];

    /**
     * The filters actually applied, normalised — sent back to the page so the UI shows the real state.
     *
     * @return array{
     *     status: list<string>, outreach: list<string>, type: list<string>, score_min: float|null, score_max: float|null,
     *     country: string|null, source: string|null, qualified_from: string|null, qualified_to: string|null,
     *     verified: bool, linkedin: bool, reject_reason: string|null, q: string|null, sort: string, dir: string, per_page: int
     * }
     */
    public function filters(Request $request): array
    {
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'score';
        $dir = $request->query('dir') === 'asc' ? 'asc' : ($request->query('dir') === 'desc' ? 'desc' : ($sort === 'name' ? 'asc' : 'desc'));
        $perPage = (int) $request->query('per_page', '25');

        $country = Str::upper(trim((string) $request->query('country', '')));
        $q = trim((string) $request->query('q', ''));

        return [
            'status' => $this->enumList($request, 'status', LeadStatus::class),
            'outreach' => $this->enumList($request, 'outreach', OutreachStatus::class),
            'type' => $this->enumList($request, 'type', CompanyType::class),
            'score_min' => $this->score($request->query('score_min')),
            'score_max' => $this->score($request->query('score_max')),
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            'source' => SignalSource::tryFrom((string) $request->query('source', ''))?->value,
            'qualified_from' => $this->date($request->query('qualified_from')),
            'qualified_to' => $this->date($request->query('qualified_to')),
            'verified' => $request->boolean('verified'),
            'linkedin' => $request->boolean('linkedin'),
            'reject_reason' => RejectReason::tryFrom((string) $request->query('reject_reason', ''))?->value,
            'q' => $q === '' ? null : Str::limit($q, 100, ''),
            'sort' => $sort,
            'dir' => $dir,
            'per_page' => in_array($perPage, self::PER_PAGE, true) ? $perPage : 25,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  from filters()
     * @return LengthAwarePaginator<int, Company>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with([
                'founders.email',
                'contactEmail',
                'signals' => fn ($q) => $q->latest('posted_at')->latest('id'),
            ])
            ->paginate((int) $filters['per_page'])
            ->withQueryString();
    }

    /**
     * The filtered, sorted query (also used by bulk "select all matching" and the export in P2.5).
     *
     * @param  array<string, mixed>  $filters  from filters()
     * @return Builder<Company>
     */
    public function query(array $filters): Builder
    {
        $query = Company::query();

        if ($filters['status'] !== []) {
            $query->whereIn('status', $filters['status']);
        }

        if ($filters['outreach'] !== []) {
            $query->whereIn('outreach_status', $filters['outreach']);
        }

        if ($filters['type'] !== []) {
            $query->whereIn('company_type', $filters['type']);
        }

        if ($filters['score_min'] !== null) {
            $query->where('score', '>=', $filters['score_min']);
        }

        if ($filters['score_max'] !== null) {
            $query->where('score', '<=', $filters['score_max']);
        }

        if ($filters['country'] !== null) {
            $query->where('country', $filters['country']);
        }

        if ($filters['source'] !== null) {
            $query->whereHas('signals', fn (Builder $q) => $q->where('source', $filters['source']));
        }

        $timezone = (string) config('leadradar.timezone', 'Europe/London');

        if ($filters['qualified_from'] !== null) {
            $query->where('qualified_at', '>=', CarbonImmutable::parse($filters['qualified_from'], $timezone)->startOfDay()->utc());
        }

        if ($filters['qualified_to'] !== null) {
            $query->where('qualified_at', '<', CarbonImmutable::parse($filters['qualified_to'], $timezone)->startOfDay()->addDay()->utc());
        }

        if ($filters['verified']) {
            $query->whereHas('founders', fn (Builder $q) => $q->whereHas(
                'email',
                fn (Builder $e) => $e->where('verification_status', EmailVerificationStatus::Ok),
            ));
        }

        if ($filters['linkedin']) {
            $query->whereHas('founders', fn (Builder $q) => $q->whereNotNull('linkedin_url'));
        }

        if ($filters['reject_reason'] !== null) {
            $query->where('status', LeadStatus::Rejected)->where('reject_reason', $filters['reject_reason']);
        }

        if ($filters['q'] !== null) {
            $this->search($query, (string) $filters['q']);
        }

        $dir = $filters['dir'] === 'asc' ? 'asc' : 'desc';

        match ($filters['sort']) {
            // Unscored companies always last, whichever way the score is sorted.
            'score' => $query->orderByRaw('score is null')->orderBy('score', $dir)->orderByDesc('qualified_at'),
            'qualified_at' => $query->orderByRaw('qualified_at is null')->orderBy('qualified_at', $dir),
            default => $query->orderBy('name', $dir),
        };

        return $query->orderByDesc('id');
    }

    /**
     * Name, domain, founder name or any email of the company.
     *
     * @param  Builder<Company>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($term)).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(legal_name) like ?', [$like])
                ->orWhereRaw('lower(domain) like ?', [$like])
                ->orWhereRaw('lower(registration_number) like ?', [$like])
                ->orWhereHas('people', fn (Builder $p) => $p->whereRaw("lower(concat(first_name, ' ', last_name)) like ?", [$like]))
                ->orWhereHas('emailAddresses', fn (Builder $e) => $e->whereRaw('lower(email) like ?', [$like]));
        });
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<string>
     */
    private function enumList(Request $request, string $key, string $enum): array
    {
        $raw = $request->query($key);
        $values = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? explode(',', $raw) : []);

        $valid = [];
        foreach ($values as $value) {
            if (is_string($value) && $enum::tryFrom($value) !== null) {
                $valid[] = $value;
            }
        }

        return array_values(array_unique($valid));
    }

    private function score(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(10.0, round((float) $value, 1)));
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->toDateString() === $value ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One table row (docs/07 §2 columns).
     *
     * @return array<string, mixed>
     */
    public static function row(Company $company): array
    {
        $signal = $company->signals->first();

        return [
            'id' => $company->id,
            'name' => $company->name,
            'domain' => $company->domain,
            'website' => $company->website,
            'score' => $company->score === null ? null : (float) $company->score,
            'type' => $company->company_type->value,
            'location' => $company->location,
            'country' => $company->country,
            'registrationNumber' => $company->registration_number,
            'founders' => $company->founders->map(fn ($person): array => [
                'id' => $person->id,
                'position' => $person->position,
                'name' => trim($person->first_name.' '.$person->last_name),
                'linkedinUrl' => $person->linkedin_url,
                'email' => $person->email?->email,
                'emailStatus' => $person->email?->verification_status->value,
            ])->values()->all(),
            'contactEmail' => $company->contactEmail === null ? null : [
                'email' => $company->contactEmail->email,
                'status' => $company->contactEmail->verification_status->value,
            ],
            'signal' => $signal === null ? null : [
                'title' => $signal->title,
                'source' => $signal->source->value,
                'url' => $signal->url,
                'postedAt' => $signal->posted_at?->toIso8601String(),
            ],
            'status' => $company->status->value,
            'rejectReason' => $company->reject_reason?->value,
            'pipelineStep' => $company->pipeline_step->value,
            'outreachStatus' => $company->outreach_status->value,
            'qualifiedAt' => $company->qualified_at?->toIso8601String(),
        ];
    }
}
