<?php

declare(strict_types=1);

use App\Enums\CompanyType;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\CrawlCompanyWebsiteJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Signal;
use App\Models\Suppression;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-07-15 13:00:00', 'UTC'));
    $this->actingAs(User::factory()->create());
});

/**
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function listedIds(array $query = []): array
{
    $ids = [];

    test()->get(route('companies.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids) {
            $page->component('companies/index');
            $ids = array_column($page->toArray()['props']['companies']['data'], 'id');
        });

    return $ids;
}

function leadWithFounder(array $attributes = [], EmailVerificationStatus $status = EmailVerificationStatus::Ok, ?string $linkedin = null): Company
{
    $company = Company::factory()->qualified()->create($attributes);
    $person = Person::factory()->for($company)->create(['first_name' => 'Jane', 'last_name' => 'Example', 'linkedin_url' => $linkedin]);
    $email = EmailAddress::factory()->forPerson($person)->verified($status)->create(['email' => 'jane@'.$company->domain]);
    $person->update(['email_id' => $email->id]);

    return $company;
}

it('redirects guests to the login page', function () {
    auth()->logout();

    $this->get(route('companies.index'))->assertRedirect(route('login'));
});

it('lists leads with every column the table needs, best score first', function () {
    $best = leadWithFounder(['name' => 'Example Digital Ltd', 'domain' => 'example-digital.test', 'score' => 9.5, 'location' => 'Leeds']);
    Signal::factory()->for($best)->create(['title' => 'Laravel Developer', 'source' => SignalSource::Reed, 'posted_at' => now()->subDays(2)]);
    $middle = Company::factory()->qualified(7.0)->create();
    $unscored = Company::factory()->discovered()->create();

    expect(listedIds())->toBe([$best->id, $middle->id, $unscored->id]);

    $this->get(route('companies.index'))->assertInertia(fn (Assert $page) => $page
        ->where('companies.total', 3)
        ->where('companies.data.0.name', 'Example Digital Ltd')
        ->where('companies.data.0.score', 9.5)
        ->where('companies.data.0.location', 'Leeds')
        ->where('companies.data.0.founders.0.name', 'Jane Example')
        ->where('companies.data.0.founders.0.email', 'jane@example-digital.test')
        ->where('companies.data.0.founders.0.emailStatus', 'ok')
        ->where('companies.data.0.signal.title', 'Laravel Developer')
        ->where('companies.data.0.status', 'qualified')
        ->where('companies.data.0.outreachStatus', 'not_contacted')
        ->where('filters.sort', 'score')
        ->where('filters.dir', 'desc')
        ->has('options.status', count(LeadStatus::cases()))
        ->has('options.step', count(PipelineStep::cases()) - 1));
});

it('filters by status, outreach, type and score range', function () {
    $agency = Company::factory()->qualified(8.5)->create(['company_type' => CompanyType::Agency]);
    $client = Company::factory()->qualified(6.5)->create(['company_type' => CompanyType::DirectClient, 'outreach_status' => OutreachStatus::Contacted]);
    $nearMiss = Company::factory()->rejected()->create(['score' => 5.0]);
    Company::factory()->create(['status' => LeadStatus::Enriching]);

    expect(listedIds(['status' => ['qualified']]))->toBe([$agency->id, $client->id])
        ->and(listedIds(['status' => 'qualified,rejected']))->toBe([$agency->id, $client->id, $nearMiss->id])
        ->and(listedIds(['outreach' => ['contacted']]))->toBe([$client->id])
        ->and(listedIds(['type' => ['direct_client']]))->toBe([$client->id])
        ->and(listedIds(['score_min' => 8]))->toBe([$agency->id])
        ->and(listedIds(['score_min' => 4, 'score_max' => 5.9]))->toBe([$nearMiss->id])
        ->and(listedIds(['status' => ['bogus']]))->toHaveCount(4);
});

it('filters by country, source, qualified date and reject reason', function () {
    $gb = Company::factory()->qualified()->create(['qualified_at' => CarbonImmutable::parse('2026-07-14 23:30', 'UTC')]); // 00:30 on 15 Jul in London
    $ie = Company::factory()->qualified()->create(['country' => 'IE', 'qualified_at' => now()->subDays(5)]);
    Signal::factory()->for($ie)->create(['source' => SignalSource::Adzuna]);
    $recruiter = Company::factory()->rejected(RejectReason::Recruiter)->create();

    expect(listedIds(['country' => 'ie']))->toBe([$ie->id])
        ->and(listedIds(['source' => 'adzuna']))->toBe([$ie->id])
        ->and(listedIds(['qualified_from' => '2026-07-15', 'qualified_to' => '2026-07-15']))->toBe([$gb->id])
        ->and(listedIds(['qualified_to' => '2026-07-14']))->toBe([$ie->id])
        ->and(listedIds(['reject_reason' => 'recruiter']))->toBe([$recruiter->id])
        ->and(listedIds(['qualified_from' => '2026-02-31']))->toHaveCount(3);
});

it('filters on a verified founder email and on LinkedIn', function () {
    $verified = leadWithFounder(['score' => 9.0], linkedin: 'https://www.linkedin.com/in/jane-example');
    $catchAll = leadWithFounder(['score' => 8.0], EmailVerificationStatus::CatchAll);
    Company::factory()->qualified(7.0)->create();

    expect(listedIds(['verified' => 1]))->toBe([$verified->id])
        ->and(listedIds(['linkedin' => 1]))->toBe([$verified->id])
        ->and(listedIds(['verified' => 0]))->toHaveCount(3)
        ->and($catchAll->exists)->toBeTrue();
});

it('searches name, domain, founder and email', function () {
    $lead = leadWithFounder(['name' => 'Sample Software Ltd', 'domain' => 'sample-software.test']);
    Company::factory()->qualified()->create(['name' => 'Other Things Ltd', 'domain' => 'other-things.test']);

    expect(listedIds(['q' => 'sample soft']))->toBe([$lead->id])
        ->and(listedIds(['q' => 'SAMPLE-SOFTWARE.TEST']))->toBe([$lead->id])
        ->and(listedIds(['q' => 'jane example']))->toBe([$lead->id])
        ->and(listedIds(['q' => 'jane@sample']))->toBe([$lead->id])
        ->and(listedIds(['q' => '100%']))->toBe([]);
});

it('sorts by score, qualified date and name, and paginates', function () {
    $a = Company::factory()->qualified(6.0)->create(['name' => 'Alpha Ltd', 'qualified_at' => now()->subDays(1)]);
    $b = Company::factory()->qualified(9.0)->create(['name' => 'Bravo Ltd', 'qualified_at' => now()->subDays(3)]);
    $c = Company::factory()->qualified(7.5)->create(['name' => 'Charlie Ltd', 'qualified_at' => now()->subDays(2)]);

    expect(listedIds(['sort' => 'score', 'dir' => 'asc']))->toBe([$a->id, $c->id, $b->id])
        ->and(listedIds(['sort' => 'qualified_at']))->toBe([$a->id, $c->id, $b->id])
        ->and(listedIds(['sort' => 'name']))->toBe([$a->id, $b->id, $c->id])
        ->and(listedIds(['sort' => 'name', 'dir' => 'desc']))->toBe([$c->id, $b->id, $a->id]);

    Company::factory()->count(30)->create();

    $this->get(route('companies.index', ['per_page' => 25, 'page' => 2, 'status' => ['discovered']]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('companies.total', 30)
            ->where('companies.currentPage', 2)
            ->where('companies.lastPage', 2)
            ->has('companies.data', 5)
            ->where('companies.prevPageUrl', fn (string $url) => str_contains($url, 'status%5B0%5D=discovered') && str_contains($url, 'page=1')));
});

// --- Bulk actions ---------------------------------------------------------------------

it('marks leads contacted without moving an earlier first-contact date', function () {
    $new = Company::factory()->qualified()->create();
    $earlier = Company::factory()->qualified()->create(['outreach_status' => OutreachStatus::Replied, 'contacted_at' => now()->subDays(10)]);

    $this->from(route('companies.index'))
        ->post(route('companies.bulk'), ['action' => 'contacted', 'ids' => [$new->id, $earlier->id]])
        ->assertRedirect(route('companies.index'))
        ->assertInertiaFlash('toast.message', '2 leads marked contacted');

    expect($new->refresh()->outreach_status)->toBe(OutreachStatus::Contacted)
        ->and($new->contacted_at?->equalTo(now()))->toBeTrue()
        ->and($earlier->refresh()->contacted_at?->equalTo(now()->subDays(10)))->toBeTrue();
});

it('marks leads not interested and suppresses their domain', function () {
    $lead = Company::factory()->qualified()->create(['domain' => 'Example-Digital.test']);
    $noDomain = Company::factory()->qualified()->create(['domain' => null, 'website' => null, 'registration_number' => '01234567']);

    $this->post(route('companies.bulk'), ['action' => 'not_interested', 'ids' => [$lead->id, $noDomain->id]])->assertRedirect();

    expect($lead->refresh()->outreach_status)->toBe(OutreachStatus::NotInterested)
        ->and(Suppression::query()->where('type', SuppressionType::Domain)->sole()->value)->toBe('example-digital.test')
        ->and(Suppression::query()->where('type', SuppressionType::CompanyNumber)->sole()->value)->toBe('01234567')
        ->and(Suppression::query()->pluck('reason')->unique()->all())->toBe([SuppressionReason::NotInterested]);
});

it('suppresses domains and stops those leads, skipping ones without a domain', function () {
    $inPipeline = Company::factory()->create(['domain' => 'example-digital.test', 'status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::Crawl]);
    $noDomain = Company::factory()->discovered()->create();

    $this->post(route('companies.bulk'), ['action' => 'suppress_domain', 'ids' => [$inPipeline->id, $noDomain->id]])
        ->assertInertiaFlash('toast.message', '1 lead suppressed · 1 skipped (no domain)');

    expect($inPipeline->refresh()->status)->toBe(LeadStatus::Suppressed)
        ->and($inPipeline->reject_reason)->toBe(RejectReason::Suppressed)
        ->and($inPipeline->pipelineEvents()->sole()->message)->toBe('Domain suppressed by hand')
        ->and($noDomain->refresh()->status)->toBe(LeadStatus::Discovered)
        ->and(Suppression::count())->toBe(1);
});

it('re-runs rejected and errored leads from a step and skips the rest', function () {
    $errored = Company::factory()->create(['status' => LeadStatus::Error, 'pipeline_step' => PipelineStep::Crawl]);
    $rejected = Company::factory()->rejected(RejectReason::NoLaravelEvidence)->create();
    $qualified = Company::factory()->qualified()->create();

    $this->post(route('companies.bulk'), ['action' => 'retry', 'ids' => [$errored->id, $rejected->id, $qualified->id], 'step' => 'crawl'])
        ->assertInertiaFlash('toast.message', '2 leads sent back into the pipeline · 1 skipped (only rejected or errored leads can be retried)');

    expect($errored->refresh()->status)->toBe(LeadStatus::Enriching)
        ->and($rejected->refresh()->pipeline_step)->toBe(PipelineStep::Crawl)
        ->and($qualified->refresh()->status)->toBe(LeadStatus::Qualified);

    Queue::assertPushed(CrawlCompanyWebsiteJob::class, 2);
});

it('validates bulk requests', function (array $payload, string $field) {
    $this->post(route('companies.bulk'), $payload)->assertSessionHasErrors($field);
})->with([
    'unknown action' => [['action' => 'delete', 'ids' => [1]], 'action'],
    'no ids' => [['action' => 'contacted', 'ids' => []], 'ids'],
    'done is not a step' => [['action' => 'retry', 'ids' => [1], 'step' => 'done'], 'step'],
]);
