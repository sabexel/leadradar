<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\BuildFounderEmailCandidatesAction;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Suppression;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function companyAwaitingCandidates(): Company
{
    return Company::factory()->create([
        'domain' => 'example-digital.test',
        'website' => 'https://www.example-digital.test',
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::BuildEmailCandidates,
    ]);
}

function founder(Company $company, int $position, string $first, string $last): Person
{
    return Person::factory()->for($company)->position($position)->create(['first_name' => $first, 'last_name' => $last]);
}

function generatedFor(Person $person): array
{
    return $person->emailAddresses()->where('origin', EmailOrigin::Generated)->orderBy('id')->pluck('email')->all();
}

it('creates generated, unverified candidates for Founder 1 and 2 in try-order', function () {
    $company = companyAwaitingCandidates();
    $jane = founder($company, 1, 'Jane', 'Example');
    $john = founder($company, 2, 'John', 'Sample');

    app(BuildFounderEmailCandidatesAction::class)->run($company);

    $rows = $company->emailAddresses()->get();

    expect(generatedFor($jane))->toBe([
        'jane@example-digital.test', 'jane.example@example-digital.test', 'janeexample@example-digital.test',
        'j.example@example-digital.test', 'jexample@example-digital.test', 'jane_example@example-digital.test',
        'example@example-digital.test',
    ])
        ->and(generatedFor($john))->toHaveCount(7)
        ->and($rows->every(fn (EmailAddress $e) => $e->kind === EmailKind::Person
            && $e->verification_status === EmailVerificationStatus::Unverified
            && $e->source_url === null))->toBeTrue()
        ->and($company->refresh()->pipeline_step)->toBe(PipelineStep::VerifyEmails)
        ->and($company->pipelineEvents()->sole()->context['created'])->toBe(14);
});

it('tries the pattern already confirmed for a colleague first', function () {
    $company = companyAwaitingCandidates();
    $jane = founder($company, 1, 'Jane', 'Example');
    $john = founder($company, 2, 'John', 'Sample');
    EmailAddress::factory()->for($company)->create([
        'person_id' => $john->id, 'email' => 'john.sample@example-digital.test',
        'kind' => EmailKind::Person, 'origin' => EmailOrigin::Crawled,
    ]);

    app(BuildFounderEmailCandidatesAction::class)->run($company);

    expect(generatedFor($jane)[0])->toBe('jane.example@example-digital.test')
        ->and($company->pipelineEvents()->sole()->context['preferred_pattern'])->toBe('first.last');
});

it('learns the pattern from a verified address too', function () {
    $company = companyAwaitingCandidates();
    $jane = founder($company, 1, 'Jane', 'Example');
    $john = founder($company, 2, 'John', 'Sample');
    EmailAddress::factory()->forPerson($john)->verified()->create(['email' => 'jsample@example-digital.test']);

    app(BuildFounderEmailCandidatesAction::class)->run($company);

    expect(generatedFor($jane)[0])->toBe('jexample@example-digital.test');
});

it('skips founders whose real address was found on the site', function () {
    $company = companyAwaitingCandidates();
    $jane = founder($company, 1, 'Jane', 'Example');
    EmailAddress::factory()->for($company)->create([
        'person_id' => $jane->id, 'email' => 'jane@example-digital.test',
        'kind' => EmailKind::Person, 'origin' => EmailOrigin::Crawled,
    ]);

    app(BuildFounderEmailCandidatesAction::class)->run($company);

    expect(generatedFor($jane))->toBe([])
        ->and($company->pipelineEvents()->sole()->context['skipped_positions'])->toBe([1]);
});

it('ignores resigned officers and directors beyond Founder 2', function () {
    $company = companyAwaitingCandidates();
    founder($company, 1, 'Jane', 'Example');
    $third = founder($company, 3, 'Pat', 'Third');
    $resigned = Person::factory()->for($company)->resigned()->create(['first_name' => 'Alex', 'last_name' => 'Demo']);

    app(BuildFounderEmailCandidatesAction::class)->run($company);

    expect(generatedFor($third))->toBe([])
        ->and(generatedFor($resigned))->toBe([])
        ->and($company->emailAddresses()->count())->toBe(7);
});

it('is idempotent', function () {
    $company = companyAwaitingCandidates();
    founder($company, 1, 'Jane', 'Example');

    app(BuildFounderEmailCandidatesAction::class)->run($company);
    app(BuildFounderEmailCandidatesAction::class)->run($company->refresh());

    expect($company->emailAddresses()->count())->toBe(7);
});

it('runs as a local pipeline step and stops at verify_emails', function () {
    $company = companyAwaitingCandidates();
    founder($company, 1, 'Jane', 'Example');

    app(AdvanceLeadPipelineAction::class)->run($company);

    expect($company->refresh()->pipeline_step)->toBe(PipelineStep::VerifyEmails)
        ->and($company->emailAddresses()->count())->toBe(7);
});

it('suppresses the company when a generated address is on the opt-out list', function () {
    Suppression::factory()->create(['type' => SuppressionType::Email, 'value' => 'jane.example@example-digital.test']);
    $company = companyAwaitingCandidates();
    founder($company, 1, 'Jane', 'Example');

    app(AdvanceLeadPipelineAction::class)->run($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Suppressed);
});
