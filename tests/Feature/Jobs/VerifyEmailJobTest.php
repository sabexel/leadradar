<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyVerificationResultAction;
use App\Actions\BuildFounderEmailCandidatesAction;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\PipelineStep;
use App\Jobs\Enrichment\VerifyEmailJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Setting;
use App\Services\Verification\MillionVerifierService;
use App\Services\Verification\VerificationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-04 09:00:00');
    config(['services.millionverifier.key' => 'test-mv-key']);
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Fake MillionVerifier: each address gets the result named in $results (default "invalid").
 *
 * @param  array<string, string>  $results
 */
function fakeVerifier(array $results = []): void
{
    Http::fake(function (Request $request) use ($results) {
        $body = jsonFixture('millionverifier/'.($results[$request['email']] ?? 'invalid').'.json');
        $body['email'] = $request['email'];

        return Http::response($body);
    });
}

function companyWithFounders(): Company
{
    $company = Company::factory()->create([
        'domain' => 'example-digital.test',
        'website' => 'https://www.example-digital.test',
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::BuildEmailCandidates,
    ]);

    Person::factory()->for($company)->position(1)->create(['first_name' => 'Jane', 'last_name' => 'Example']);
    Person::factory()->for($company)->position(2)->create(['first_name' => 'John', 'last_name' => 'Sample']);

    app(BuildFounderEmailCandidatesAction::class)->run($company);

    return $company->refresh();
}

/**
 * Run the pipeline and every VerifyEmailJob it queues, in order.
 *
 * @return list<string> verified addresses, in call order
 */
function verifyAll(Company $company): array
{
    app(AdvanceLeadPipelineAction::class)->run($company);

    $ran = [];

    for ($i = 0; $i < 40; $i++) {
        $job = collect(Queue::pushed(VerifyEmailJob::class))->first(fn ($j) => ! in_array(spl_object_id($j), $ran, true));

        if ($job === null) {
            break;
        }

        $ran[] = spl_object_id($job);
        runVerifyJob($job);
    }

    return Http::recorded()->map(fn ($pair) => $pair[0]['email'])->values()->all();
}

function runVerifyJob(VerifyEmailJob $queued): VerifyEmailJob
{
    $job = (new VerifyEmailJob($queued->companyId, $queued->emailAddressId))->withFakeQueueInteractions();
    $job->handle(app(MillionVerifierService::class), app(VerificationPlanner::class), app(ApplyVerificationResultAction::class), app(AdvanceLeadPipelineAction::class));

    return $job;
}

it('walks each founder\'s guesses in order and stops at the first ok', function () {
    fakeVerifier([
        'jane.example@example-digital.test' => 'ok',
        'john@example-digital.test' => 'ok',
    ]);
    $company = companyWithFounders();

    $calls = verifyAll($company);
    $company->refresh();

    $jane = $company->founders->first();
    $john = $company->founders->last();

    expect($calls)->toBe([
        'jane@example-digital.test',          // invalid
        'jane.example@example-digital.test',  // ok → Jane done
        'john@example-digital.test',          // ok → John done
    ])
        ->and($jane->email->email)->toBe('jane.example@example-digital.test')
        ->and($jane->email->verification_status)->toBe(EmailVerificationStatus::Ok)
        ->and($john->email->email)->toBe('john@example-digital.test')
        ->and(EmailAddress::where('email', 'jane@example-digital.test')->sole()->verification_status)->toBe(EmailVerificationStatus::Invalid)
        ->and(EmailAddress::where('email', 'jane@example-digital.test')->sole()->verification_raw['result'])->toBe('invalid')
        ->and($company->pipeline_step)->toBe(PipelineStep::FindLinkedIn);
});

it('verifies a crawled address before any guess', function () {
    fakeVerifier(['jane.e@example-digital.test' => 'ok', 'john@example-digital.test' => 'ok']);
    $company = companyWithFounders();
    $jane = $company->founders->first();
    EmailAddress::factory()->for($company)->create([
        'person_id' => $jane->id, 'email' => 'jane.e@example-digital.test',
        'kind' => EmailKind::Person, 'origin' => EmailOrigin::Crawled,
    ]);

    expect(verifyAll($company)[0])->toBe('jane.e@example-digital.test')
        ->and($jane->refresh()->email->email)->toBe('jane.e@example-digital.test');
});

it('also verifies the contact and careers inboxes', function () {
    fakeVerifier(['jane@example-digital.test' => 'ok', 'john@example-digital.test' => 'ok', 'hello@example-digital.test' => 'ok']);
    $company = companyWithFounders();
    $contact = EmailAddress::factory()->for($company)->create(['email' => 'hello@example-digital.test', 'kind' => EmailKind::Contact]);
    $careers = EmailAddress::factory()->for($company)->create(['email' => 'jobs@example-digital.test', 'kind' => EmailKind::Careers]);
    $company->update(['contact_email_id' => $contact->id, 'careers_email_id' => $careers->id]);

    expect(verifyAll($company))->toBe([
        'jane@example-digital.test',
        'john@example-digital.test',
        'hello@example-digital.test',
        'jobs@example-digital.test',
    ])
        ->and($contact->refresh()->verification_status)->toBe(EmailVerificationStatus::Ok);
});

it('stops verifying a catch-all domain after the first answer', function () {
    fakeVerifier(['jane@example-digital.test' => 'catch_all']);
    $company = companyWithFounders();

    $calls = verifyAll($company);
    $company->refresh();

    expect($calls)->toBe(['jane@example-digital.test'])
        ->and($company->founders->first()->email->verification_status)->toBe(EmailVerificationStatus::CatchAll)
        ->and($company->founders->last()->email_id)->toBeNull() // unverified guesses are not an address
        ->and($company->pipeline_step)->toBe(PipelineStep::FindLinkedIn);
});

it('gives a founder no address when every guess fails', function () {
    fakeVerifier(); // everything invalid
    $company = companyWithFounders();

    expect(verifyAll($company))->toHaveCount(14)
        ->and($company->refresh()->founders->every(fn (Person $p) => $p->email_id === null))->toBeTrue();
});

it('never re-verifies within 90 days, but does after', function () {
    fakeVerifier(['jane@example-digital.test' => 'ok', 'john@example-digital.test' => 'ok']);
    $company = companyWithFounders();
    EmailAddress::where('email', 'jane@example-digital.test')->update(['verification_status' => EmailVerificationStatus::Ok, 'verified_at' => now()->subDays(10)]);
    EmailAddress::where('email', 'john@example-digital.test')->update(['verification_status' => EmailVerificationStatus::Ok, 'verified_at' => now()->subDays(100)]);

    expect(verifyAll($company))->toBe(['john@example-digital.test']);
});

it('releases the job until 06:00 London when the daily cap is used, without failing', function () {
    Setting::put('cap.millionverifier.per_day', 0);
    Http::fake();
    $company = companyWithFounders();
    app(AdvanceLeadPipelineAction::class)->run($company);

    $job = runVerifyJob(Queue::pushed(VerifyEmailJob::class)->first());

    $job->assertReleased(21 * 3600);
    $job->assertNotFailed();
    Http::assertNothingSent();
    expect($company->refresh()->pipeline_step)->toBe(PipelineStep::VerifyEmails)
        ->and($company->status)->toBe(LeadStatus::Enriching);
});

it('releases instead of failing when the balance is empty', function () {
    Http::fake(['*' => Http::response(jsonFixture('millionverifier/no-credits.json'))]);
    $company = companyWithFounders();
    app(AdvanceLeadPipelineAction::class)->run($company);

    $job = runVerifyJob(Queue::pushed(VerifyEmailJob::class)->first());

    $job->assertReleased();
    expect(EmailAddress::where('email', 'jane@example-digital.test')->sole()->verification_status)->toBe(EmailVerificationStatus::Unverified);
});

it('does not spend a second credit when a job runs twice', function () {
    fakeVerifier(['jane@example-digital.test' => 'ok']);
    $company = companyWithFounders();
    app(AdvanceLeadPipelineAction::class)->run($company);
    $first = Queue::pushed(VerifyEmailJob::class)->first();

    runVerifyJob($first);
    $company->update(['pipeline_step' => PipelineStep::VerifyEmails]); // pretend the step is still open
    runVerifyJob($first);

    Http::assertSentCount(1);
});

it('moves straight on when there is nothing to verify', function () {
    Http::fake();
    $company = Company::factory()->create(['status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::VerifyEmails]);

    app(AdvanceLeadPipelineAction::class)->run($company);

    Http::assertNothingSent();
    Queue::assertNotPushed(VerifyEmailJob::class);
    expect($company->pipelineEvents()->where('step', PipelineStep::VerifyEmails)->exists())->toBeTrue()
        // no founders → nobody to look up on LinkedIn either → qualify
        ->and($company->refresh()->pipeline_step)->toBe(PipelineStep::Qualify);
});

it('queues one job per address on the verify queue', function () {
    $company = companyWithFounders();

    app(AdvanceLeadPipelineAction::class)->run($company);

    $job = Queue::pushed(VerifyEmailJob::class)->sole();

    expect($job->queue)->toBe('verify')
        ->and($job->uniqueId())->toBe("verify:{$job->emailAddressId}")
        ->and(EmailAddress::find($job->emailAddressId)->email)->toBe('jane@example-digital.test');
});
