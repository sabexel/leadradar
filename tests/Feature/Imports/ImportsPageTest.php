<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Jobs\Enrichment\SearchCompaniesHouseJob;
use App\Models\Company;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Dedupe\CompanyMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

function fixtureUpload(string $name): UploadedFile
{
    $path = dirname(__DIR__, 2)."/Fixtures/imports/{$name}";

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

it('shows the imports page to signed-in users only', function () {
    Suppression::factory()->count(2)->create();

    $this->get(route('imports.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('imports/index')
        ->where('suppressionCount', 2)
        ->where('maxRows', 500)
        ->has('reasons', count(SuppressionReason::cases())));

    auth()->logout();
    $this->get(route('imports.index'))->assertRedirect(route('login'));
});

// --- Company list ---------------------------------------------------------------------

it('imports a pasted list of names, domains and "name, domain" lines into the pipeline', function () {
    $this->post(route('imports.companies'), ['text' => "Example Digital Ltd\nhttps://www.sample-software.co.uk/about\n\nDemo Web Labs, demowebl.test\n"])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', '3 new companies sent into the pipeline');

    $byName = Company::query()->orderBy('id')->get()->keyBy('name');

    expect($byName->keys()->all())->toBe(['Example Digital Ltd', 'Sample Software', 'Demo Web Labs'])
        ->and($byName['Example Digital Ltd']->domain)->toBeNull()
        ->and($byName['Sample Software']->domain)->toBe('sample-software.co.uk')
        ->and($byName['Sample Software']->website)->toBe('https://sample-software.co.uk')
        ->and($byName['Demo Web Labs']->domain)->toBe('demowebl.test');

    $company = $byName['Demo Web Labs'];
    expect($company->signals()->sole()->source)->toBe(SignalSource::Import)
        ->and($company->status)->toBe(LeadStatus::Enriching)
        ->and($company->pipeline_step)->toBe(PipelineStep::RegistrySearch)
        ->and($company->pipelineEvents()->first()?->message)->toBe('Imported from a list');

    Queue::assertPushed(SearchCompaniesHouseJob::class, 3);
});

it('skips companies already known, repeated in the list, or suppressed', function () {
    Company::factory()->create(['domain' => 'example-digital.test']);
    Company::factory()->create(['name' => 'Known Name Ltd', 'normalised_name' => app(CompanyMatcher::class)->normalise('Known Name Ltd'), 'domain' => 'known.test']);
    Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'contacted.test']);

    $this->post(route('imports.companies'), ['text' => implode("\n", [
        'example-digital.test',          // known by domain
        'Known Name Limited',            // known by name
        'contacted.test',                // suppressed
        'New One Ltd, new-one.test',
        'new-one.test',                  // repeated in the list
        '!!!',                           // unreadable
    ])])->assertInertiaFlash('toast.message', '1 new company sent into the pipeline · 3 already known · 1 on the do-not-contact list · 1 unreadable');

    expect(Company::query()->where('domain', 'new-one.test')->exists())->toBeTrue()
        ->and(Company::count())->toBe(3);
});

it('previews a CSV\'s columns, then imports with the detected or chosen columns', function () {
    $this->postJson(route('imports.preview'), ['type' => 'companies', 'file' => fixtureUpload('apollo-export.csv')])
        ->assertOk()
        ->assertExactJson([
            'headers' => ['First Name', 'Last Name', 'Account Name', 'Company Website', 'Industry'],
            'rows' => 3,
            'detected' => ['name' => 'Account Name', 'website' => 'Company Website'],
        ]);

    $this->post(route('imports.companies'), ['file' => fixtureUpload('apollo-export.csv'), 'name_column' => 'Account Name', 'website_column' => 'Company Website'])
        ->assertInertiaFlash('toast.message', '3 new companies sent into the pipeline');

    expect(Company::query()->orderBy('id')->pluck('domain', 'name')->all())->toBe([
        'Example Digital Ltd' => 'example-digital.test',
        'Sample Software Ltd' => 'sample-software.test',
        'Demo Web Labs' => null,
    ]);
});

it('refuses an empty import, a missing column and an oversized list', function () {
    $this->post(route('imports.companies'), [])->assertSessionHasErrors(['text', 'file']);

    $this->post(route('imports.companies'), ['file' => fixtureUpload('apollo-export.csv'), 'name_column' => 'Company'])
        ->assertSessionHasErrors(['file' => 'Column "Company" not found in the CSV header.']);

    $this->post(route('imports.companies'), ['text' => implode("\n", array_map(fn (int $i) => "Company {$i} Ltd", range(1, 501)))])
        ->assertSessionHasErrors(['text' => 'At most 500 companies per import.']);

    expect(Company::count())->toBe(0);
});

// --- Suppression import -----------------------------------------------------------------

it('previews the outreach sheet with the detected suppression columns', function () {
    $this->postJson(route('imports.preview'), ['type' => 'suppressions', 'file' => fixtureUpload('outreach-sheet.csv')])
        ->assertOk()
        ->assertJsonPath('rows', 4)
        ->assertJsonPath('detected.website', ['Website'])
        ->assertJsonPath('detected.company_number', ['Company House no'])
        ->assertJsonPath('detected.email', ['Company contact email', 'Company careers email', 'Founder 1 email', 'Founder 2 email']);

    $this->postJson(route('imports.preview'), ['type' => 'suppressions', 'file' => fixtureUpload('no-columns.csv')])
        ->assertOk()
        ->assertJsonPath('detected', ['website' => [], 'email' => [], 'company_number' => []]);

    $this->postJson(route('imports.preview'), ['type' => 'bogus', 'file' => fixtureUpload('outreach-sheet.csv')])->assertUnprocessable();
});

it('imports suppressions with the chosen columns and reason, and stops matching leads', function () {
    $lead = Company::factory()->qualified()->create(['domain' => 'example-digital.test']);

    $this->post(route('imports.suppressions'), [
        'file' => fixtureUpload('outreach-sheet.csv'),
        'reason' => 'already_contacted',
        'website' => ['Website'],
        'email' => [],
        'company_number' => [],
    ])->assertInertiaFlash('toast.message', '4 rows read. New suppressions: 2 domains, 0 emails, 0 company numbers (0 already listed). 1 existing lead stopped.');

    expect(Suppression::query()->pluck('type')->unique()->all())->toBe([SuppressionType::Domain])
        ->and($lead->refresh()->status)->toBe(LeadStatus::Suppressed)
        ->and($lead->outreach_status)->toBe(OutreachStatus::Contacted);
});

it('uses auto-detection when no columns are sent, and refuses a bad reason', function () {
    $this->post(route('imports.suppressions'), ['file' => fixtureUpload('outreach-sheet.csv'), 'reason' => 'opted_out'])
        ->assertInertiaFlash('toast.type', 'success');

    expect(Suppression::count())->toBe(13)
        ->and(Suppression::query()->pluck('reason')->unique()->all())->toBe([SuppressionReason::OptedOut]);

    $this->post(route('imports.suppressions'), ['file' => fixtureUpload('outreach-sheet.csv'), 'reason' => 'bogus'])->assertSessionHasErrors('reason');
    $this->post(route('imports.suppressions'), ['file' => fixtureUpload('no-columns.csv'), 'reason' => 'manual'])
        ->assertSessionHasErrors('file');
});
