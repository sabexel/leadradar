<?php

declare(strict_types=1);

use App\Enums\EmailVerificationStatus;
use App\Enums\OutreachStatus;
use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

function mailmeteorUpload(string $name = 'mailmeteor-results.csv'): UploadedFile
{
    return new UploadedFile(dirname(__DIR__, 2)."/Fixtures/imports/{$name}", $name, 'text/csv', null, true);
}

function leadWithEmail(string $email, array $company = []): EmailAddress
{
    $lead = Company::factory()->create(['domain' => substr($email, strpos($email, '@') + 1), ...$company]);

    return EmailAddress::factory()->for($lead)->verified()->create(['email' => $email]);
}

it('detects the recipient and Mailmeteor status columns in the preview', function () {
    $this->post(route('imports.preview'), ['file' => mailmeteorUpload(), 'type' => 'outreach'])
        ->assertOk()
        ->assertJson([
            'rows' => 9,
            'detected' => ['email' => 'Email', 'status' => ['Merge status', 'Unsubscribed']],
        ]);
});

it('brings sent, replied, bounced and unsubscribed results back onto the leads', function () {
    $one = leadWithEmail('founder@lead-one.test')->company;
    $two = leadWithEmail('jo@lead-two.test')->company;

    $three = Company::factory()->create(['domain' => 'lead-three.test']);
    $founder = Person::factory()->for($three)->create();
    $bounced = EmailAddress::factory()->forPerson($founder)->verified()->create(['email' => 'old@lead-three.test']);
    $other = EmailAddress::factory()->forPerson($founder)->verified()->create(['email' => 'alex@lead-three.test']);
    $founder->update(['email_id' => $bounced->id]);

    $four = leadWithEmail('info@lead-four.test')->company;
    $five = leadWithEmail('later@lead-five.test')->company;
    $six = Company::factory()->create(['domain' => 'lead-six.test']);

    $this->post(route('imports.outreach'), ['file' => mailmeteorUpload()])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', '8 addresses read. Leads updated: 4 contacted, 1 replied, 1 bounced, 1 unsubscribed. 2 not in LeadRadar (now on the do-not-contact list), 1 not sent yet or failed.');

    expect($one->refresh()->outreach_status)->toBe(OutreachStatus::Contacted)
        ->and($one->contacted_at)->not->toBeNull()
        ->and($one->pipelineEvents()->latest('id')->first()?->message)->toBe('Mailmeteor: email sent')
        ->and($two->refresh()->outreach_status)->toBe(OutreachStatus::Replied)
        ->and($two->contacted_at)->not->toBeNull()
        ->and($six->refresh()->outreach_status)->toBe(OutreachStatus::Contacted)
        ->and($five->refresh()->outreach_status)->toBe(OutreachStatus::NotContacted);

    // A bounce is not a contact: the address is marked invalid and the founder falls back to the other one.
    expect($three->refresh()->outreach_status)->toBe(OutreachStatus::NotContacted)
        ->and($bounced->refresh()->verification_status)->toBe(EmailVerificationStatus::Invalid)
        ->and($bounced->verification_raw)->toMatchArray(['subresult' => 'bounced', 'source' => 'mailmeteor'])
        ->and($founder->refresh()->email_id)->toBe($other->id);

    expect($four->refresh()->outreach_status)->toBe(OutreachStatus::NotInterested)
        ->and(Suppression::query()->where(['type' => SuppressionType::Email, 'value' => 'info@lead-four.test'])->value('reason'))->toBe(SuppressionReason::OptedOut)
        ->and(Suppression::query()->where(['type' => SuppressionType::Domain, 'value' => 'lead-four.test'])->exists())->toBeTrue();

    // Unmatched recipients never come back as leads; a free-mail domain is not suppressed as a whole.
    expect(Suppression::query()->where('type', SuppressionType::Email)->whereIn('value', ['someone@stranger.test', 'friend@gmail.com'])->count())->toBe(2)
        ->and(Suppression::query()->where(['type' => SuppressionType::Domain, 'value' => 'stranger.test'])->value('reason'))->toBe(SuppressionReason::AlreadyContacted)
        ->and(Suppression::query()->where(['type' => SuppressionType::Domain, 'value' => 'gmail.com'])->exists())->toBeFalse()
        ->and(Suppression::query()->where('value', 'like', '%lead-five%')->exists())->toBeFalse();
});

it('is safe to import the same export twice and never moves a lead backwards', function () {
    $client = leadWithEmail('jo@lead-two.test', ['outreach_status' => OutreachStatus::Client])->company;
    $contacted = leadWithEmail('founder@lead-one.test')->company;

    $this->post(route('imports.outreach'), ['file' => mailmeteorUpload()]);
    $first = $contacted->refresh()->contacted_at;

    $this->travel(2)->days();
    $this->post(route('imports.outreach'), ['file' => mailmeteorUpload()])->assertRedirect();

    expect($client->refresh()->outreach_status)->toBe(OutreachStatus::Client)
        ->and($contacted->refresh()->contacted_at->equalTo($first))->toBeTrue()
        ->and($contacted->pipelineEvents()->where('message', 'Mailmeteor: email sent')->count())->toBe(1)
        ->and(Suppression::query()->where('value', 'someone@stranger.test')->count())->toBe(1);
});

it('counts every row as sent when no status column is chosen', function () {
    $lead = leadWithEmail('later@lead-five.test')->company;

    $this->post(route('imports.outreach'), ['file' => mailmeteorUpload(), 'email_column' => 'Email', 'status' => []])
        ->assertRedirect();

    expect($lead->refresh()->outreach_status)->toBe(OutreachStatus::Contacted);
});

it('rejects a file without an email column, or a chosen column that is not there', function () {
    $this->post(route('imports.outreach'), ['file' => mailmeteorUpload('no-columns.csv')])
        ->assertSessionHasErrors('file');

    $this->post(route('imports.outreach'), ['file' => mailmeteorUpload(), 'email_column' => 'Recipient', 'status' => []])
        ->assertSessionHasErrors(['file' => 'Column "Recipient" not found in the CSV header.']);
});
