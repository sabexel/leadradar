<?php

declare(strict_types=1);

use App\Actions\ExportCompaniesCsvAction;
use App\Actions\ImportContactedCsvAction;
use App\Enums\CompanyType;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Signal;
use App\Models\Suppression;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-15 13:05:00', 'UTC'));
    $this->actingAs(User::factory()->create());
});

/**
 * @return list<list<string>>
 */
function csvRows(TestResponse $response): array
{
    $body = $response->streamedContent();
    expect(str_starts_with($body, "\u{FEFF}"))->toBeTrue();

    $lines = preg_split('/\r\n|\n/', trim(substr($body, 3)));

    return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines ?: []);
}

function exportLead(): Company
{
    $company = Company::factory()->qualified(8.5)->create([
        'name' => 'Example Digital Ltd',
        'website' => 'https://example-digital.test',
        'domain' => 'example-digital.test',
        'location' => 'Leeds',
        'registration_number' => '07172996',
        'company_type' => CompanyType::Agency,
    ]);

    $jane = Person::factory()->for($company)->create(['position' => 1, 'first_name' => 'Jane', 'last_name' => 'Example', 'linkedin_url' => 'https://www.linkedin.com/in/jane-example']);
    $janeEmail = EmailAddress::factory()->forPerson($jane)->verified()->create(['email' => 'jane@example-digital.test']);
    $jane->update(['email_id' => $janeEmail->id]);

    $john = Person::factory()->for($company)->create(['position' => 2, 'first_name' => 'John', 'last_name' => 'Sample']);
    $johnEmail = EmailAddress::factory()->forPerson($john)->create(['email' => 'john.sample@example-digital.test']); // unverified guess
    $john->update(['email_id' => $johnEmail->id]);

    $contact = EmailAddress::factory()->for($company)->verified()->create(['email' => 'hello@example-digital.test', 'kind' => EmailKind::Contact, 'origin' => EmailOrigin::Crawled]);
    $careers = EmailAddress::factory()->for($company)->create(['email' => 'careers@example-digital.test', 'kind' => EmailKind::Careers, 'origin' => EmailOrigin::Crawled]);
    $company->update(['contact_email_id' => $contact->id, 'careers_email_id' => $careers->id]);

    Signal::factory()->for($company)->create(['title' => 'Old ad', 'url' => 'https://jobs.example.test/1', 'posted_at' => now()->subDays(20)]);
    Signal::factory()->for($company)->create(['title' => 'Laravel Developer', 'url' => 'https://jobs.example.test/2', 'posted_at' => now()->subDays(2)]);

    return $company;
}

it('exports in the outreach-sheet column order with text company numbers', function () {
    exportLead();

    $response = $this->get(route('companies.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('leadradar-leads-2026-07-15-1405.csv');

    $rows = csvRows($response);

    expect($rows[0])->toBe(ExportCompaniesCsvAction::HEADER)
        ->and($rows[0])->toBe([
            'S.no', 'Company Name', 'Website', 'Location', 'Company House no', 'Company contact email', 'Company careers email',
            'Founder 1 First Name', 'Founder 1 Last Name', 'Founder 1 LinkedIn', 'Founder 1 email', 'Founder 1 email test',
            'Founder 2 First Name', 'Founder 2 Last Name', 'Founder 2 LinkedIn', 'Founder 2 email', 'Founder 2 email test',
            'Score', 'Company type', 'Signal', 'Signal URL',
        ])
        ->and($rows[1])->toBe([
            '1', 'Example Digital Ltd', 'https://example-digital.test', 'Leeds', '="07172996"',
            'hello@example-digital.test', 'careers@example-digital.test',
            'Jane', 'Example', 'https://www.linkedin.com/in/jane-example', 'jane@example-digital.test', 'Verified',
            'John', 'Sample', '', 'john.sample@example-digital.test', 'Generated',
            '8.5', 'Agency', 'Laravel Developer', 'https://jobs.example.test/2',
        ]);
});

it('maps every verification result to the sheet\'s email test values', function (EmailVerificationStatus $status, EmailOrigin $origin, string $expected) {
    $email = EmailAddress::factory()->create(['verification_status' => $status, 'origin' => $origin]);

    expect(app(ExportCompaniesCsvAction::class)->emailTest($email))->toBe($expected);
})->with([
    [EmailVerificationStatus::Ok, EmailOrigin::Generated, 'Verified'],
    [EmailVerificationStatus::CatchAll, EmailOrigin::Crawled, 'Catch-all'],
    [EmailVerificationStatus::Unknown, EmailOrigin::Generated, 'Unknown'],
    [EmailVerificationStatus::Error, EmailOrigin::Generated, 'Unknown'],
    [EmailVerificationStatus::Invalid, EmailOrigin::Generated, 'Invalid'],
    [EmailVerificationStatus::Disposable, EmailOrigin::Crawled, 'Invalid'],
    [EmailVerificationStatus::Unverified, EmailOrigin::Generated, 'Generated'],
    [EmailVerificationStatus::Unverified, EmailOrigin::Crawled, 'Unknown'],
]);

it('exports the table\'s filters and order, or only the selected rows', function () {
    $best = Company::factory()->qualified(9.0)->create(['name' => 'Best Ltd']);
    $good = Company::factory()->qualified(7.0)->create(['name' => 'Good Ltd']);
    Company::factory()->rejected()->create(['name' => 'Rejected Ltd', 'score' => 4.0]);

    $names = fn (array $query) => array_column(array_slice(csvRows($this->get(route('companies.export', $query))), 1), 1);

    expect($names(['status' => ['qualified']]))->toBe(['Best Ltd', 'Good Ltd'])
        ->and($names(['status' => ['qualified'], 'sort' => 'score', 'dir' => 'asc']))->toBe(['Good Ltd', 'Best Ltd'])
        ->and($names(['ids' => [$good->id]]))->toBe(['Good Ltd'])
        ->and($names([]))->toHaveCount(3);

    // Numbering follows the export, not the database id.
    expect(array_column(array_slice(csvRows($this->get(route('companies.export', ['ids' => [$good->id, $best->id]]))), 1), 0))->toBe(['1', '2']);
});

it('leaves missing founders and data empty', function () {
    Company::factory()->discovered()->create(['name' => 'Bare Ltd', 'location' => null]);

    $row = csvRows($this->get(route('companies.export')))[1];
    $expected = array_fill(0, 21, '');
    $expected[0] = '1';
    $expected[1] = 'Bare Ltd';
    $expected[18] = 'Unknown'; // company type

    expect($row)->toBe($expected);
});

it('neutralises spreadsheet formulas in scraped text', function () {
    $company = Company::factory()->qualified()->create(['name' => '=HYPERLINK("http://evil.test")', 'location' => '+44 Leeds']);
    Signal::factory()->for($company)->create(['title' => '@SUM(1+1)']);

    $row = csvRows($this->get(route('companies.export')))[1];

    expect($row[1])->toBe('\'=HYPERLINK("http://evil.test")')
        ->and($row[3])->toBe("'+44 Leeds")
        ->and($row[19])->toBe("'@SUM(1+1)");
});

it('round-trips into the suppression importer', function () {
    exportLead();
    $path = tempnam(sys_get_temp_dir(), 'leadradar-export');
    file_put_contents($path, $this->get(route('companies.export'))->streamedContent());

    try {
        app(ImportContactedCsvAction::class)->run($path);
    } finally {
        unlink($path);
    }

    expect(Suppression::query()->where('type', SuppressionType::CompanyNumber)->pluck('value')->all())->toBe(['07172996'])
        ->and(Suppression::query()->where('type', SuppressionType::Domain)->pluck('value')->all())->toBe(['example-digital.test'])
        ->and(Suppression::query()->where('type', SuppressionType::Email)->count())->toBe(4)
        ->and(Company::query()->sole()->status)->toBe(LeadStatus::Suppressed);
});

it('refuses guests and oversized selections', function () {
    $this->get(route('companies.export', ['ids' => range(1, 501)]))->assertSessionHasErrors('ids');

    auth()->logout();
    $this->get(route('companies.export'))->assertRedirect(route('login'));
});
