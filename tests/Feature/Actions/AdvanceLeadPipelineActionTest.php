<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Enums\EmailKind;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Suppression;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function advance(Company $company): Company
{
    app(AdvanceLeadPipelineAction::class)->run($company);

    return $company->refresh();
}

it('suppresses a company whose domain is on the list and records why', function () {
    $company = Company::factory()->create(['domain' => 'example-digital.test', 'pipeline_step' => PipelineStep::FindWebsite]);
    Suppression::factory()->create([
        'type' => SuppressionType::Domain,
        'value' => 'Example-Digital.test',
        'reason' => SuppressionReason::AlreadyContacted,
    ]);

    advance($company);

    $event = $company->pipelineEvents()->sole();

    expect($company->status)->toBe(LeadStatus::Suppressed)
        ->and($company->reject_reason)->toBe(RejectReason::Suppressed)
        ->and($event->step)->toBe(PipelineStep::FindWebsite)
        ->and($event->outcome)->toBe(PipelineOutcome::Rejected)
        ->and($event->context)->toBe(['type' => 'domain', 'reason' => 'already_contacted']);
});

it('suppresses by company number and by any company email', function (string $type) {
    $company = Company::factory()->create(['registration_number' => '01234567']);
    EmailAddress::factory()->for($company)->create(['email' => 'hello@example-digital.test', 'kind' => EmailKind::Contact]);

    Suppression::factory()->create($type === 'number'
        ? ['type' => SuppressionType::CompanyNumber, 'value' => '01234567']
        : ['type' => SuppressionType::Email, 'value' => 'HELLO@example-digital.test']);

    expect(advance($company)->status)->toBe(LeadStatus::Suppressed);
})->with(['number', 'email']);

it('moves companies that match nothing on to their next step', function () {
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'someone-else.test']);
    $company = Company::factory()->create(['domain' => 'example-digital.test']);

    expect(advance($company)->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipelineEvents)->toHaveCount(0);

    Queue::assertPushed(SearchCompaniesHouseJob::class);
});

it('does nothing for companies that already finished', function (LeadStatus $status) {
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'example-digital.test']);
    $company = Company::factory()->create(['domain' => 'example-digital.test', 'status' => $status]);

    expect(advance($company)->status)->toBe($status)
        ->and($company->pipelineEvents)->toHaveCount(0);
})->with([LeadStatus::Qualified, LeadStatus::Rejected, LeadStatus::Error]);
