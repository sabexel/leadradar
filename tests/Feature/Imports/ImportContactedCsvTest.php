<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\ApplyVerificationResultAction;
use App\Actions\ApplyWebsiteResultAction;
use App\Actions\ImportContactedCsvAction;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineStep;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\FindCompanyWebsiteJob;
use App\Jobs\Enrichment\VerifyEmailJob;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Suppression;
use App\Services\Search\BraveSearchService;
use App\Services\Verification\MillionVerifierService;
use App\Services\Verification\VerificationPlanner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function importFixture(string $name): string
{
    return dirname(__DIR__, 2)."/Fixtures/imports/{$name}";
}

/**
 * @return list<string>
 */
function suppressed(SuppressionType $type): array
{
    return Suppression::query()->where('type', $type)->orderBy('value')->pluck('value')->all();
}

it('suppresses every domain, email and company number in the owner\'s sheet export', function () {
    $result = app(ImportContactedCsvAction::class)->run(importFixture('outreach-sheet.csv'));

    expect(suppressed(SuppressionType::Domain))->toBe(['demo-web-labs.test', 'example-digital.test', 'sample-software.test'])
        ->and(suppressed(SuppressionType::Email))->toBe([
            'careers@example-digital.test', 'hello@example-digital.test', 'info@demo-web-labs.test',
            'jane@example-digital.test', 'john.sample@example-digital.test', 'pat.founder@gmail.com',
            'sales@demo-web-labs.test',
        ])
        ->and(suppressed(SuppressionType::CompanyNumber))->toBe(['01234567', '07172996', 'sc123456'])
        ->and(Suppression::query()->pluck('reason')->unique()->all())->toBe([SuppressionReason::AlreadyContacted])
        ->and($result->rows)->toBe(4)
        ->and($result->created())->toBe(13)
        ->and($result->columns['website'])->toBe(['Website'])
        ->and($result->columns['email'])->toBe(['Company contact email', 'Company careers email', 'Founder 1 email', 'Founder 2 email'])
        ->and($result->columns['company_number'])->toBe(['Company House no']);
});

it('never suppresses job boards, LinkedIn or free-mail domains from the sheet', function () {
    app(ImportContactedCsvAction::class)->run(importFixture('outreach-sheet.csv'));

    expect(suppressed(SuppressionType::Domain))->not->toContain('reed.co.uk')
        ->and(suppressed(SuppressionType::Domain))->not->toContain('adzuna.co.uk')
        ->and(suppressed(SuppressionType::Domain))->not->toContain('linkedin.com')
        ->and(suppressed(SuppressionType::Domain))->not->toContain('gmail.com');
});

it('is idempotent and reports what was already on the list', function () {
    app(ImportContactedCsvAction::class)->run(importFixture('outreach-sheet.csv'));
    $again = app(ImportContactedCsvAction::class)->run(importFixture('outreach-sheet.csv'));

    expect(Suppression::count())->toBe(13)
        ->and($again->created())->toBe(0)
        ->and($again->alreadySuppressed)->toBe(13);
});

it('reads semicolon-separated files and honours an explicit column mapping', function () {
    $result = app(ImportContactedCsvAction::class)->run(importFixture('semicolon.csv'), ['website' => ['Site'], 'email' => ['Mail']]);

    expect(suppressed(SuppressionType::Domain))->toBe(['semi-colon.test'])
        ->and(suppressed(SuppressionType::Email))->toBe(['team@semi-colon.test'])
        ->and($result->columns['website'])->toBe(['Site']);
});

it('refuses a file with no usable column, or a mapping to a missing column', function (string $file, ?array $mapping, string $message) {
    expect(fn () => app(ImportContactedCsvAction::class)->run(importFixture($file), $mapping))
        ->toThrow(InvalidArgumentException::class, $message);

    expect(Suppression::count())->toBe(0);
})->with([
    ['no-columns.csv', null, 'No website, email or company number column found'],
    ['outreach-sheet.csv', ['website' => ['Homepage']], 'Column "Homepage" not found'],
]);

it('stops existing leads that are on the list and marks them contacted', function () {
    $inPipeline = Company::factory()->create(['domain' => 'example-digital.test', 'status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::Crawl]);
    $qualified = Company::factory()->qualified()->create(['domain' => 'sample-software.test']);
    $byNumber = Company::factory()->create(['domain' => 'other.test', 'registration_number' => 'SC123456']);
    $untouched = Company::factory()->create(['domain' => 'unrelated.test']);

    $result = app(ImportContactedCsvAction::class)->run(importFixture('outreach-sheet.csv'));

    expect($result->leadsSuppressed)->toBe(3)
        ->and($inPipeline->refresh()->status)->toBe(LeadStatus::Suppressed)
        ->and($inPipeline->outreach_status)->toBe(OutreachStatus::Contacted)
        ->and($qualified->refresh()->status)->toBe(LeadStatus::Suppressed)
        ->and($byNumber->refresh()->status)->toBe(LeadStatus::Suppressed)
        ->and($untouched->refresh()->status)->not->toBe(LeadStatus::Suppressed)
        ->and($inPipeline->pipelineEvents()->sole()->message)->toBe('Suppressed by import');
});

it('works from the command line with a reason', function () {
    $this->artisan('leadradar:import-contacted', ['file' => importFixture('outreach-sheet.csv'), '--reason' => 'opted_out'])
        ->expectsOutputToContain('4 rows read. New suppressions: 3 domains, 7 emails, 3 company numbers')
        ->assertSuccessful();

    expect(Suppression::query()->pluck('reason')->unique()->all())->toBe([SuppressionReason::OptedOut]);

    $this->artisan('leadradar:import-contacted', ['file' => importFixture('missing.csv')])->assertFailed();
    $this->artisan('leadradar:import-contacted', ['file' => importFixture('outreach-sheet.csv'), '--reason' => 'bogus'])->assertExitCode(2);
});

// --- Checked before every paid call ----------------------------------------------------

it('makes no paid call for a company suppressed after its job was queued', function () {
    config(['services.brave.key' => 'test-brave-key', 'services.millionverifier.key' => 'test-mv-key']);
    Http::fake();
    $company = Company::factory()->create([
        'registration_number' => '01234567', 'website' => null, 'domain' => null,
        'status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::FindWebsite,
    ]);

    // Queued first, suppressed afterwards (e.g. by an import in the meantime)…
    Suppression::factory()->create(['type' => SuppressionType::CompanyNumber, 'value' => '01234567']);

    $job = (new FindCompanyWebsiteJob($company->id))->withFakeQueueInteractions();
    $job->handle(app(BraveSearchService::class), app(ApplyWebsiteResultAction::class), app(AdvanceLeadPipelineAction::class));

    Http::assertNothingSent();
    expect($company->refresh()->status)->toBe(LeadStatus::Suppressed);
});

it('makes no MillionVerifier call for a suppressed email address', function () {
    config(['services.millionverifier.key' => 'test-mv-key']);
    Http::fake();
    $company = Company::factory()->create(['domain' => 'example-digital.test', 'status' => LeadStatus::Enriching, 'pipeline_step' => PipelineStep::VerifyEmails]);
    $email = EmailAddress::factory()->for($company)->create(['email' => 'jane@example-digital.test']);
    Suppression::factory()->create(['type' => SuppressionType::Email, 'value' => 'jane@example-digital.test']);

    (new VerifyEmailJob($company->id, $email->id))->withFakeQueueInteractions()->handle(
        app(MillionVerifierService::class),
        app(VerificationPlanner::class),
        app(ApplyVerificationResultAction::class),
        app(AdvanceLeadPipelineAction::class),
    );

    Http::assertNothingSent();
    expect($company->refresh()->status)->toBe(LeadStatus::Suppressed);
});
