<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RetryPipelineStepAction;
use App\Actions\SuppressCompanyAction;
use App\Actions\UpdateOutreachStatusAction;
use App\Enums\CompanyType;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Http\Requests\BulkCompanyActionRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Jobs\Enrichment\ReverifyEmailJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Queries\CompanyDetailQuery;
use App\Queries\CompanyIndexQuery;
use App\Services\Support\SuppressionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController extends Controller
{
    public function index(Request $request, CompanyIndexQuery $query): Response
    {
        $filters = $query->filters($request);
        $companies = $query->paginate($filters);

        return Inertia::render('companies/index', [
            'filters' => $filters,
            'companies' => [
                'data' => $companies->getCollection()->map(fn (Company $c): array => CompanyIndexQuery::row($c))->values()->all(),
                'total' => $companies->total(),
                'from' => $companies->firstItem(),
                'to' => $companies->lastItem(),
                'currentPage' => $companies->currentPage(),
                'lastPage' => $companies->lastPage(),
                'prevPageUrl' => $companies->previousPageUrl(),
                'nextPageUrl' => $companies->nextPageUrl(),
            ],
            'options' => [
                'status' => $this->options(LeadStatus::cases()),
                'outreach' => $this->options(OutreachStatus::cases()),
                'type' => $this->options(CompanyType::cases()),
                'source' => array_map(fn (SignalSource $s): array => ['value' => $s->value, 'label' => $s->label()], SignalSource::cases()),
                'rejectReason' => array_map(fn (RejectReason $r): array => ['value' => $r->value, 'label' => $r->label()], RejectReason::cases()),
                'step' => $this->options(array_slice(PipelineStep::cases(), 0, -1)), // every step but `done`
                'country' => Company::query()->distinct()->orderBy('country')->pluck('country')->filter()->values()->all(),
            ],
        ]);
    }

    public function show(Company $company, CompanyDetailQuery $query): Response
    {
        return Inertia::render('companies/show', [
            ...$query->get($company),
            'options' => [
                'outreach' => $this->options(OutreachStatus::cases()),
                'step' => $this->options(array_slice(PipelineStep::cases(), 0, -1)), // every step but `done`
            ],
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company, UpdateOutreachStatusAction $outreach): RedirectResponse
    {
        if ($request->has('notes')) {
            $notes = trim((string) $request->input('notes'));
            $company->update(['notes' => $notes === '' ? null : $notes]);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Notes saved']);
        }

        if ($request->filled('outreach_status')) {
            $status = OutreachStatus::from($request->string('outreach_status')->toString());
            $outreach->run($company, $status);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Outreach status: '.Str::of($status->value)->replace('_', ' ')]);
        }

        return back();
    }

    public function retry(Request $request, Company $company, RetryPipelineStepAction $retry): RedirectResponse
    {
        $validated = $request->validate([
            'step' => ['nullable', Rule::enum(PipelineStep::class)->except([PipelineStep::Done])],
        ]);

        if (! $retry->canRetry($company)) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Only rejected or errored leads can be retried']);

            return back();
        }

        $step = isset($validated['step']) ? PipelineStep::from((string) $validated['step']) : $company->pipeline_step;
        $retry->run($company, $step);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent back into the pipeline from '.Str::of($step->value)->replace('_', ' ')]);

        return back();
    }

    public function reverify(Company $company, EmailAddress $emailAddress, SuppressionList $suppressions): RedirectResponse
    {
        if ($suppressions->isSuppressed($company)) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'This lead is on the do-not-contact list — no credit spent']);

            return back();
        }

        ReverifyEmailJob::dispatch($emailAddress->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Re-verifying {$emailAddress->email} — refresh in a minute"]);

        return back();
    }

    public function bulk(
        BulkCompanyActionRequest $request,
        UpdateOutreachStatusAction $outreach,
        SuppressCompanyAction $suppress,
        RetryPipelineStepAction $retry,
    ): RedirectResponse {
        $action = $request->string('action')->toString();
        $step = $request->filled('step') ? PipelineStep::from($request->string('step')->toString()) : null;

        $done = 0;
        $skipped = 0;

        Company::query()->whereIn('id', $request->collect('ids')->map(fn (mixed $id): int => (int) $id)->all())
            ->orderBy('id')
            ->each(function (Company $company) use ($action, $step, $outreach, $suppress, $retry, &$done, &$skipped): void {
                $ok = match ($action) {
                    'contacted' => (bool) $outreach->run($company, OutreachStatus::Contacted),
                    'not_interested' => (bool) $outreach->run($company, OutreachStatus::NotInterested),
                    'suppress_domain' => $suppress->run($company),
                    'retry' => $retry->canRetry($company) && (bool) $retry->run($company, $step),
                    default => false,
                };

                $ok ? $done++ : $skipped++;
            });

        $label = match ($action) {
            'contacted' => 'marked contacted',
            'not_interested' => 'marked not interested',
            'suppress_domain' => 'suppressed',
            default => 'sent back into the pipeline',
        };

        $message = $done.' '.Str::plural('lead', $done).' '.$label;

        if ($skipped > 0) {
            $message .= match ($action) {
                'suppress_domain' => " · {$skipped} skipped (no domain)",
                'retry' => " · {$skipped} skipped (only rejected or errored leads can be retried)",
                default => " · {$skipped} skipped",
            };
        }

        Inertia::flash('toast', ['type' => $done > 0 ? 'success' : 'warning', 'message' => $message]);

        return back();
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(fn (\BackedEnum $case): array => [
            'value' => (string) $case->value,
            'label' => Str::of((string) $case->value)->replace(['_', '-'], ' ')->ucfirst()->toString(),
        ], $cases);
    }
}
