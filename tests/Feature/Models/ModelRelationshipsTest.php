<?php

declare(strict_types=1);

use App\Enums\CompanyType;
use App\Enums\EmailKind;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\PipelineStep;
use App\Enums\SignalSource;
use App\Models\Company;
use App\Models\CrawledPage;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\PipelineEvent;
use App\Models\Signal;
use Illuminate\Database\QueryException;

it('creates a company with enum defaults', function () {
    $company = Company::create(['name' => 'Example Digital Ltd', 'normalised_name' => 'example digital'])->refresh();

    expect($company->status)->toBe(LeadStatus::Discovered)
        ->and($company->pipeline_step)->toBe(PipelineStep::RegistrySearch)
        ->and($company->company_type)->toBe(CompanyType::Unknown)
        ->and($company->outreach_status)->toBe(OutreachStatus::NotContacted)
        ->and($company->country)->toBe('GB');
});

it('casts json, date and decimal columns', function () {
    $company = Company::factory()->qualified(7.25)->create([
        'sic_codes' => ['62012', '73110'],
        'laravel_evidence' => [['type' => 'job_ad', 'detail' => 'Laravel Developer', 'url' => null]],
    ])->refresh();

    expect($company->sic_codes)->toBe(['62012', '73110'])
        ->and($company->laravel_evidence[0]['type'])->toBe('job_ad')
        ->and($company->score)->toBe('7.3')
        ->and($company->qualified_at)->not->toBeNull();
});

it('keeps leading zeros in registration numbers', function () {
    $company = Company::factory()->create(['registration_number' => '01234567'])->refresh();

    expect($company->registration_number)->toBe('01234567');
});

it('links people, emails, signals, pages and events to a company', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->for($company)->create();
    EmailAddress::factory()->forPerson($person)->create(['email' => 'jane@example.test']);
    Signal::factory()->for($company)->create();
    CrawledPage::factory()->for($company)->create();
    PipelineEvent::factory()->for($company)->create();

    $company->load('people', 'emailAddresses', 'signals', 'crawledPages', 'pipelineEvents');

    expect($company->people)->toHaveCount(1)
        ->and($company->emailAddresses)->toHaveCount(1)
        ->and($company->emailAddresses->first()->person->is($person))->toBeTrue()
        ->and($company->signals)->toHaveCount(1)
        ->and($company->crawledPages)->toHaveCount(1)
        ->and($company->pipelineEvents)->toHaveCount(1);
});

it('selects founders 1 and 2 in order and never resigned people', function () {
    $company = Company::factory()->create();
    $second = Person::factory()->for($company)->position(2)->create();
    $first = Person::factory()->for($company)->position(1)->create();
    Person::factory()->for($company)->resigned()->create();
    Person::factory()->for($company)->position(3)->create();

    expect($company->founders->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('links contact, careers and person emails', function () {
    $company = Company::factory()->create();
    $contact = EmailAddress::factory()->for($company)->create(['email' => 'hello@example.test']);
    $careers = EmailAddress::factory()->for($company)->create(['email' => 'jobs@example.test', 'kind' => EmailKind::Careers]);
    $person = Person::factory()->for($company)->create();
    $personal = EmailAddress::factory()->forPerson($person)->verified()->create(['email' => 'jane@example.test']);

    $company->update(['contact_email_id' => $contact->id, 'careers_email_id' => $careers->id]);
    $person->update(['email_id' => $personal->id]);

    expect($company->refresh()->contactEmail->email)->toBe('hello@example.test')
        ->and($company->careersEmail->kind)->toBe(EmailKind::Careers)
        ->and($person->refresh()->email->verification_status)->toBe(EmailVerificationStatus::Ok)
        ->and($person->emailAddresses)->toHaveCount(1);
});

it('stores emails lowercased and unique per company', function () {
    $company = Company::factory()->create();
    EmailAddress::factory()->for($company)->create(['email' => ' Hello@Example.TEST ']);

    expect(EmailAddress::first()->email)->toBe('hello@example.test');

    EmailAddress::factory()->for($company)->create(['email' => 'hello@example.test']);
})->throws(QueryException::class);

it('enforces one signal per source and external id', function () {
    Signal::factory()->create(['source' => SignalSource::Reed, 'external_id' => '123']);
    Signal::factory()->create(['source' => SignalSource::Adzuna, 'external_id' => '123']);

    expect(Signal::count())->toBe(2);

    Signal::factory()->create(['source' => SignalSource::Reed, 'external_id' => '123']);
})->throws(QueryException::class);

it('enforces a unique company domain', function () {
    Company::factory()->create(['domain' => 'example.test']);
    Company::factory()->create(['domain' => 'example.test']);
})->throws(QueryException::class);

it('deletes dependants with the company but keeps signals', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->for($company)->create();
    $email = EmailAddress::factory()->forPerson($person)->create();
    $person->update(['email_id' => $email->id]);
    $company->update(['contact_email_id' => $email->id]);
    $signal = Signal::factory()->for($company)->create();
    PipelineEvent::factory()->for($company)->create();

    $company->delete();

    expect(Person::count())->toBe(0)
        ->and(EmailAddress::count())->toBe(0)
        ->and(PipelineEvent::count())->toBe(0)
        ->and($signal->refresh()->company_id)->toBeNull();
});

it('exposes a person full name', function () {
    $person = Person::factory()->make(['first_name' => 'Jane', 'last_name' => 'Example']);

    expect($person->full_name)->toBe('Jane Example')
        ->and($person->isActive())->toBeTrue();
});
