<?php

declare(strict_types=1);

use App\Actions\ApplyReverificationAction;
use App\Actions\QualifyCompanyAction;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Jobs\Enrichment\ReverifyEmailJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Signal;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Support\SuppressionList;
use App\Services\Verification\MillionVerifierService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

/**
 * A qualified lead with one founder and two tried addresses (one invalid guess, one verified).
 *
 * @return array{0: Company, 1: Person, 2: EmailAddress, 3: EmailAddress}
 */
function detailedLead(): array
{
    $company = Company::factory()->create([
        'name' => 'Example Digital Ltd',
        'domain' => 'example-digital.test',
        'website' => 'https://example-digital.test',
        'registration_number' => '01234567',
        'laravel_evidence' => [['type' => 'laravel_mention', 'weight' => 'strong', 'detail' => 'We build with Laravel', 'url' => 'https://example-digital.test/services']],
        'offshore_evidence' => [],
    ]);
    $person = Person::factory()->for($company)->create(['first_name' => 'Jane', 'last_name' => 'Example', 'position' => 1]);
    $guess = EmailAddress::factory()->forPerson($person)->verified(EmailVerificationStatus::Invalid)->create(['email' => 'j.example@example-digital.test']);
    $real = EmailAddress::factory()->forPerson($person)->verified()->create([
        'email' => 'jane@example-digital.test', 'origin' => EmailOrigin::Crawled, 'source_url' => 'https://example-digital.test/about',
    ]);
    $person->update(['email_id' => $real->id]);
    $contact = EmailAddress::factory()->for($company)->verified()->create(['email' => 'hello@example-digital.test', 'kind' => EmailKind::Contact, 'origin' => EmailOrigin::Crawled]);
    $company->update(['contact_email_id' => $contact->id, 'company_type' => 'agency']);
    Signal::factory()->for($company)->create(['title' => 'Laravel Developer', 'posted_at' => now()->subDays(3)]);

    $company->update(['status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::Qualify]);
    app(QualifyCompanyAction::class)->run($company->refresh());

    return [$company->refresh(), $person, $guess, $real];
}

it('redirects guests and 404s an unknown lead', function () {
    $this->get(route('companies.show', 999))->assertNotFound();

    auth()->logout();
    $this->get(route('companies.show', 1))->assertRedirect(route('login'));
});

it('shows the company, founders, candidates, score breakdown, evidence and timeline', function () {
    [$company, , $guess, $real] = detailedLead();

    $this->get(route('companies.show', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('companies/show')
            ->where('company.name', 'Example Digital Ltd')
            ->where('company.status', 'qualified')
            ->where('company.companiesHouseUrl', 'https://find-and-update.company-information.service.gov.uk/company/01234567')
            ->where('company.contactEmail.email', 'hello@example-digital.test')
            ->where('company.contactEmail.status', 'ok')
            ->where('company.canRetry', false)
            ->where('founders.0.name', 'Jane Example')
            ->where('founders.0.email.id', $real->id)
            ->where('founders.0.email.sourceUrl', 'https://example-digital.test/about')
            ->where('founders.0.candidates.0.id', $guess->id)
            ->where('founders.0.candidates.0.status', 'invalid')
            ->has('founders.0.candidates', 2)
            ->has('scoreBreakdown.lines', 5)
            ->where('scoreBreakdown.lines.0.key', 'laravel_evidence')
            ->where('scoreBreakdown.lines.0.items.job_ad', 1.5)
            ->where('scoreBreakdown.lines.1.daysOld', 3)
            ->where('scoreBreakdown.total', (float) $company->score)
            ->where('laravelEvidence.0.detail', 'We build with Laravel')
            ->where('signals.0.title', 'Laravel Developer')
            ->where('events.0.step', 'qualify')
            ->has('options.step', count(PipelineStep::cases()) - 1));
});

it('shows a lead that has not been scored yet', function () {
    $company = Company::factory()->discovered()->create();

    $this->get(route('companies.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('scoreBreakdown', null)
        ->where('founders', [])
        ->where('company.companiesHouseUrl', null));
});

it('saves notes and clears them when emptied', function () {
    $company = Company::factory()->create();

    $this->patch(route('companies.update', $company), ['notes' => '  Spoke to Jane, follow up in May  '])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Notes saved');
    expect($company->refresh()->notes)->toBe('Spoke to Jane, follow up in May');

    $this->patch(route('companies.update', $company), ['notes' => '']);
    expect($company->refresh()->notes)->toBeNull();

    $this->patch(route('companies.update', $company), ['notes' => str_repeat('x', 10001)])->assertSessionHasErrors('notes');
});

it('changes the outreach status: contacted stamps the first contact, not interested suppresses', function () {
    $company = Company::factory()->qualified()->create(['domain' => 'example-digital.test']);

    $this->patch(route('companies.update', $company), ['outreach_status' => 'contacted'])->assertRedirect();
    $first = $company->refresh()->contacted_at;
    expect($company->outreach_status)->toBe(OutreachStatus::Contacted)->and($first)->not->toBeNull();

    $this->travel(2)->days();
    $this->patch(route('companies.update', $company), ['outreach_status' => 'replied']);
    expect($company->refresh()->outreach_status)->toBe(OutreachStatus::Replied)
        ->and($company->contacted_at?->equalTo($first))->toBeTrue();

    $this->patch(route('companies.update', $company), ['outreach_status' => 'not_interested']);
    expect(Suppression::query()->sole())
        ->type->toBe(SuppressionType::Domain)
        ->value->toBe('example-digital.test')
        ->reason->toBe(SuppressionReason::NotInterested);

    $this->patch(route('companies.update', $company), ['outreach_status' => 'bogus'])->assertSessionHasErrors('outreach_status');
});

it('retries an errored lead from a step and refuses a qualified one', function () {
    $errored = Company::factory()->create(['status' => LeadStatus::Error, 'pipeline_step' => PipelineStep::Crawl]);
    $errored->recordEvent(PipelineStep::Crawl, PipelineOutcome::Failed, 'Connection timed out');

    $this->get(route('companies.show', $errored))->assertInertia(fn (Assert $page) => $page->where('company.canRetry', true));

    $this->post(route('companies.retry', $errored), ['step' => 'crawl'])
        ->assertInertiaFlash('toast.message', 'Sent back into the pipeline from crawl');
    expect($errored->refresh()->status)->toBe(LeadStatus::Enriching);
    Queue::assertPushed(CrawlCompanyWebsiteJob::class);

    $qualified = Company::factory()->qualified()->create();
    $this->post(route('companies.retry', $qualified))->assertInertiaFlash('toast.type', 'warning');
    expect($qualified->refresh()->status)->toBe(LeadStatus::Qualified);

    $this->post(route('companies.retry', $errored), ['step' => 'done'])->assertSessionHasErrors('step');
});

it('queues a re-verify for one of the company\'s addresses only', function () {
    [$company, , , $real] = detailedLead();
    $other = EmailAddress::factory()->create();

    $this->post(route('companies.emails.reverify', [$company, $real]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Re-verifying jane@example-digital.test — refresh in a minute');
    Queue::assertPushed(ReverifyEmailJob::class, fn (ReverifyEmailJob $job) => $job->emailAddressId === $real->id);

    $this->post(route('companies.emails.reverify', [$company, $other]))->assertNotFound();
});

it('spends no credit re-verifying a suppressed lead', function () {
    [$company, , , $real] = detailedLead();
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'example-digital.test']);

    $this->post(route('companies.emails.reverify', [$company, $real]))->assertInertiaFlash('toast.type', 'warning');
    Queue::assertNotPushed(ReverifyEmailJob::class);
});

// --- The re-verify job ----------------------------------------------------------------

it('re-verifies with one MillionVerifier call, re-picks the founder email and rescores the lead', function () {
    config(['services.millionverifier.key' => 'test-mv-key']);
    [$company, $person, , $real] = detailedLead();
    expect($company->status)->toBe(LeadStatus::Qualified);
    $before = (float) $company->score;

    Http::fake(['*' => Http::response(['email' => 'jane@example-digital.test', 'result' => 'invalid', 'subresult' => 'mailbox_not_found', 'quality' => 'bad', 'credits' => 500])]);

    (new ReverifyEmailJob($real->id))->withFakeQueueInteractions()->handle(
        app(MillionVerifierService::class),
        app(SuppressionList::class),
        app(ApplyReverificationAction::class),
    );

    Http::assertSentCount(1);
    expect($real->refresh()->verification_status)->toBe(EmailVerificationStatus::Invalid)
        ->and($person->refresh()->email_id)->toBeNull() // the only other candidate is an invalid guess
        ->and($company->refresh()->pipelineEvents()->where('message', 'like', 'Re-verified jane@%')->exists())->toBeTrue()
        // Still contactable through the verified contact inbox, but the founder points are gone.
        ->and((float) $company->score)->toBeLessThan($before)
        ->and($company->score_breakdown['decision_maker_reach']['items']['founder_1_email'] ?? null)->toEqual(0);
});

it('rejects a lead whose only good address turns out invalid', function () {
    config(['services.millionverifier.key' => 'test-mv-key']);
    [$company, , , $real] = detailedLead();
    $company->contactEmail?->update(['verification_status' => EmailVerificationStatus::Invalid]);

    Http::fake(['*' => Http::response(['email' => 'jane@example-digital.test', 'result' => 'invalid', 'subresult' => 'mailbox_not_found', 'quality' => 'bad', 'credits' => 500])]);

    (new ReverifyEmailJob($real->id))->withFakeQueueInteractions()->handle(
        app(MillionVerifierService::class),
        app(SuppressionList::class),
        app(ApplyReverificationAction::class),
    );

    expect($company->refresh()->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::NoValidEmail);
});
