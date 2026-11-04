<?php

declare(strict_types=1);

use App\Actions\AdvanceLeadPipelineAction;
use App\Actions\QualifyCompanyAction;
use App\DTOs\Scoring\LeadScore;
use App\Enums\CompanyType;
use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\LeadStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Enums\RejectReason;
use App\Enums\SignalSource;
use App\Enums\SuppressionType;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Signal;
use App\Models\Suppression;
use App\Services\Scoring\LeadScoringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-04 09:00:00');
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A lead that scores the full 10.0: every gate passes and every criterion is maxed.
 */
function perfectLead(): Company
{
    $company = Company::factory()->create([
        'name' => 'Example Digital Ltd',
        'country' => 'GB',
        'registry' => 'companies_house',
        'registration_number' => '01234567',
        'company_status' => 'active',
        'company_kind' => 'ltd',
        'accounts_category' => 'micro-entity',
        'incorporated_on' => '2015-02-10',
        'company_type' => CompanyType::Agency,
        'website' => 'https://www.example-digital.test',
        'domain' => 'example-digital.test',
        'status' => LeadStatus::Enriching,
        'pipeline_step' => PipelineStep::Qualify,
        'laravel_evidence' => [
            ['type' => 'laravel_mention', 'weight' => 'strong', 'detail' => '"Laravel" on services page', 'url' => 'https://www.example-digital.test/services'],
            ['type' => 'laravel_partner', 'weight' => 'strong', 'detail' => 'Links to laravel.com/partners', 'url' => 'https://www.example-digital.test/'],
            ['type' => 'runs_laravel', 'weight' => 'medium', 'detail' => 'Laravel session cookie', 'url' => 'https://www.example-digital.test/'],
        ],
    ]);

    Signal::factory()->for($company)->create(['source' => SignalSource::Reed, 'title' => 'Laravel Developer', 'posted_at' => now()->subDays(3)]);

    $jane = Person::factory()->for($company)->position(1)->create(['first_name' => 'Jane', 'last_name' => 'Example', 'linkedin_url' => 'https://www.linkedin.com/in/jane-example']);
    $john = Person::factory()->for($company)->position(2)->create(['first_name' => 'John', 'last_name' => 'Sample']);

    $janeEmail = EmailAddress::factory()->forPerson($jane)->verified()->create(['email' => 'jane@example-digital.test']);
    $johnEmail = EmailAddress::factory()->forPerson($john)->verified(EmailVerificationStatus::CatchAll)->create(['email' => 'john@example-digital.test']);
    $jane->update(['email_id' => $janeEmail->id]);
    $john->update(['email_id' => $johnEmail->id]);

    $contact = EmailAddress::factory()->for($company)->verified()->create(['email' => 'hello@example-digital.test', 'kind' => EmailKind::Contact]);
    $careers = EmailAddress::factory()->for($company)->create(['email' => 'careers@example-digital.test', 'kind' => EmailKind::Careers]);
    $company->update(['contact_email_id' => $contact->id, 'careers_email_id' => $careers->id]);

    return $company->refresh();
}

function scoreOf(Company $company): LeadScore
{
    return app(LeadScoringService::class)->score($company->refresh());
}

function line(Company $company, string $criterion): float
{
    return scoreOf($company)->breakdown[$criterion]['points'];
}

function founderNo(Company $company, int $position): Person
{
    return $company->people()->where('position', $position)->sole();
}

it('scores a perfect lead 10.0 with every gate passing', function () {
    $score = scoreOf(perfectLead());

    expect($score->total)->toBe(10.0)
        ->and($score->failedGate)->toBeNull()
        ->and(collect($score->breakdown)->map(fn ($l) => $l['points'])->all())->toBe([
            'laravel_evidence' => 2.5,
            'timing' => 2.0,
            'company_fit' => 2.0,
            'decision_maker_reach' => 2.5,
            'company_inboxes' => 1.0,
        ]);
});

// --- Laravel evidence (max 2.5) ------------------------------------------------------------

it('scores Laravel evidence line by line, capped at 2.5', function (array $evidence, bool $ad, float $points) {
    $company = perfectLead();
    $company->update(['laravel_evidence' => $evidence]);

    if (! $ad) {
        $company->signals()->delete();
    }

    expect(line($company, 'laravel_evidence'))->toBe($points);
})->with([
    'job ad only' => [[], true, 1.5],
    'services/careers mention only' => [[['type' => 'laravel_mention', 'weight' => 'strong', 'detail' => 'x']], false, 1.0],
    'medium mention does not count' => [[['type' => 'laravel_mention', 'weight' => 'medium', 'detail' => 'x']], false, 0.0],
    'partner only' => [[['type' => 'laravel_partner', 'weight' => 'strong', 'detail' => 'x']], false, 1.0],
    'site runs Livewire only' => [[['type' => 'livewire', 'weight' => 'medium', 'detail' => 'x']], false, 0.5],
    'site runs Inertia only' => [[['type' => 'inertia', 'weight' => 'medium', 'detail' => 'x']], false, 0.5],
    'ad + mention' => [[['type' => 'laravel_mention', 'weight' => 'strong', 'detail' => 'x']], true, 2.5],
    'everything is capped' => [[
        ['type' => 'laravel_mention', 'weight' => 'strong', 'detail' => 'x'],
        ['type' => 'laravel_partner', 'weight' => 'strong', 'detail' => 'y'],
        ['type' => 'runs_laravel', 'weight' => 'medium', 'detail' => 'z'],
    ], true, 2.5],
]);

it('only counts job-board ads that mention Laravel as Laravel ads', function () {
    $company = perfectLead();
    $company->signals()->delete();
    $company->update(['laravel_evidence' => []]);
    Signal::factory()->for($company)->create(['source' => SignalSource::Reed, 'title' => 'PHP Developer', 'description' => 'Symfony shop']);
    Signal::factory()->for($company)->create(['source' => SignalSource::Manual, 'title' => 'Laravel Developer']);

    expect(line($company, 'laravel_evidence'))->toBe(0.0)
        ->and(line($company, 'timing'))->toBe(0.0);
});

// --- Timing (max 2.0) ----------------------------------------------------------------------

it('scores the freshness of the newest Laravel ad, with 7/8, 14/15 and 30/31-day boundaries', function (int $days, float $points) {
    $company = perfectLead();
    $company->signals()->update(['posted_at' => now()->subDays($days)]);

    expect(line($company, 'timing'))->toBe($points);
})->with([
    [0, 2.0], [7, 2.0], [8, 1.5], [14, 1.5], [15, 1.0], [30, 1.0], [31, 0.0],
]);

it('uses the newest of several ads and scores 0 with no dated ad', function () {
    $company = perfectLead();
    $company->signals()->update(['posted_at' => now()->subDays(40)]);
    Signal::factory()->for($company)->create(['source' => SignalSource::Adzuna, 'title' => 'Laravel Engineer', 'posted_at' => now()->subDays(10)]);

    expect(line($company, 'timing'))->toBe(1.5);

    $company->signals()->update(['posted_at' => null]);

    expect(line($company, 'timing'))->toBe(0.0);
});

// --- Company fit (max 2.0) -----------------------------------------------------------------

it('scores company fit', function (array $attributes, float $points) {
    $company = perfectLead();
    $company->update($attributes);

    expect(line($company, 'company_fit'))->toBe($points);
})->with([
    'agency, micro, established' => [[], 2.0],
    'direct client' => [['company_type' => CompanyType::DirectClient], 1.5],
    'unknown type' => [['company_type' => CompanyType::Unknown], 1.0],
    'small accounts' => [['accounts_category' => 'small'], 2.0],
    'abridged small accounts' => [['accounts_category' => 'unaudited-abridged'], 2.0],
    'full accounts' => [['accounts_category' => 'full'], 1.5],
    'exactly 2 years old' => [['incorporated_on' => '2024-11-04'], 2.0],
    'just under 2 years' => [['incorporated_on' => '2024-11-05'], 1.5],
]);

// --- Decision-maker reach (max 2.5) --------------------------------------------------------

it('gives Founder 1 +1.5 for ok, +0.5 for catch_all, nothing otherwise', function (EmailVerificationStatus $status, float $points) {
    $company = perfectLead();
    founderNo($company, 1)->email->update(['verification_status' => $status]);

    expect(line($company, 'decision_maker_reach'))->toBe($points);
})->with([
    [EmailVerificationStatus::Ok, 2.5],
    [EmailVerificationStatus::CatchAll, 1.5],
    [EmailVerificationStatus::Unknown, 1.0],
]);

it('scores Founder 1 LinkedIn and Founder 2 with any email', function () {
    $company = perfectLead();
    founderNo($company, 1)->update(['linkedin_url' => null]);

    expect(line($company, 'decision_maker_reach'))->toBe(2.0);

    founderNo($company, 2)->update(['email_id' => null]);

    expect(line($company, 'decision_maker_reach'))->toBe(1.5);
});

// --- Company inboxes (max 1.0) -------------------------------------------------------------

it('scores the contact inbox only when ok, and the careers inbox when found', function () {
    $company = perfectLead();
    $company->contactEmail->update(['verification_status' => EmailVerificationStatus::CatchAll]);

    expect(line($company, 'company_inboxes'))->toBe(0.5);

    $company->update(['careers_email_id' => null]);

    expect(line($company, 'company_inboxes'))->toBe(0.0);
});

// --- Qualification ---------------------------------------------------------------------

it('qualifies at exactly the threshold and rejects just below it (6.0 vs 5.9)', function () {
    Setting::put('qualify_threshold', 9.5);
    $company = perfectLead();
    $company->contactEmail->update(['verification_status' => EmailVerificationStatus::Unknown]); // 10.0 → 9.5

    app(QualifyCompanyAction::class)->run($company->refresh());

    expect($company->refresh()->status)->toBe(LeadStatus::Qualified)
        ->and($company->score)->toBe('9.5');

    Setting::put('qualify_threshold', 9.6);
    app(QualifyCompanyAction::class)->run($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe(RejectReason::BelowThreshold);
});

it('applies the default 6.0 threshold at its boundary', function () {
    $company = perfectLead();
    // 10.0 − 2.0 timing − 1.0 inboxes − 0.5 Founder 2 − 0.5 LinkedIn = 6.0
    $company->signals()->update(['posted_at' => now()->subDays(60)]);
    $company->update(['contact_email_id' => null, 'careers_email_id' => null]);
    founderNo($company, 2)->update(['email_id' => null]);
    founderNo($company, 1)->update(['linkedin_url' => null]);

    app(QualifyCompanyAction::class)->run($company->refresh());
    expect($company->refresh()->score)->toBe('6.0')->and($company->status)->toBe(LeadStatus::Qualified);

    $company->update(['accounts_category' => 'full']); // −0.5 → 5.5
    app(QualifyCompanyAction::class)->run($company->refresh());
    expect($company->refresh()->status)->toBe(LeadStatus::Rejected)->and($company->reject_reason)->toBe(RejectReason::BelowThreshold);
});

it('stores the score, breakdown, qualified_at and a timeline entry, and keeps qualified_at on rescore', function () {
    $company = perfectLead();

    app(QualifyCompanyAction::class)->run($company);
    $company->refresh();
    $first = $company->qualified_at;

    expect($company->status)->toBe(LeadStatus::Qualified)
        ->and($company->pipeline_step)->toBe(PipelineStep::Done)
        ->and($company->score_breakdown['total'])->toEqual(10.0)
        ->and($company->score_breakdown['timing']['days_old'])->toBe(3)
        ->and($company->pipelineEvents()->where('step', PipelineStep::Qualify)->sole()->outcome)->toBe(PipelineOutcome::Ok)
        ->and($first)->not->toBeNull();

    $this->travel(2)->hours();
    app(QualifyCompanyAction::class)->run($company);

    expect($company->refresh()->qualified_at->equalTo($first))->toBeTrue();
});

// --- Gates (one fixture per reject reason) -------------------------------------------------

it('rejects on each hard gate and still records the score', function (Closure $break, RejectReason $reason) {
    $company = perfectLead();
    $break($company);

    app(QualifyCompanyAction::class)->run($company->refresh());
    $company->refresh();

    expect($company->status)->toBe(LeadStatus::Rejected)
        ->and($company->reject_reason)->toBe($reason)
        ->and($company->score)->not->toBeNull()
        ->and($company->score_breakdown['failed_gate'])->toBe($reason->value);
})->with([
    'not on registry' => [fn (Company $c) => $c->update(['registration_number' => null]), RejectReason::NotOnRegistry],
    'non-UK' => [fn (Company $c) => $c->update(['country' => 'NL']), RejectReason::NotOnRegistry],
    'not active' => [fn (Company $c) => $c->update(['company_status' => 'dissolved']), RejectReason::NotActive],
    'wrong legal form' => [fn (Company $c) => $c->update(['company_kind' => 'private-unlimited']), RejectReason::WrongLegalForm],
    'dormant' => [fn (Company $c) => $c->update(['accounts_category' => 'dormant']), RejectReason::Dormant],
    'recruiter' => [fn (Company $c) => $c->update(['company_type' => CompanyType::Recruiter]), RejectReason::Recruiter],
    'offshore' => [fn (Company $c) => $c->update(['company_type' => CompanyType::Offshore]), RejectReason::Offshore],
    'no website' => [fn (Company $c) => $c->update(['website' => null, 'domain' => null]), RejectReason::NoWebsite],
    'no valid email' => [fn (Company $c) => $c->emailAddresses()->update(['verification_status' => EmailVerificationStatus::Invalid]), RejectReason::NoValidEmail],
    'no Laravel evidence' => [function (Company $c) {
        $c->signals()->delete();
        $c->update(['laravel_evidence' => [['type' => 'runs_laravel', 'weight' => 'medium', 'detail' => 'x']]]);
    }, RejectReason::NoLaravelEvidence],
    'suppressed' => [fn (Company $c) => Suppression::factory()->create(['type' => SuppressionType::Domain, 'value' => 'example-digital.test']), RejectReason::Suppressed],
]);

it('treats a crawled catch_all founder address as contactable, but not a generated one', function () {
    $company = perfectLead();
    $company->emailAddresses()->update(['verification_status' => EmailVerificationStatus::CatchAll]);
    $jane = founderNo($company, 1);

    $jane->email->update(['origin' => EmailOrigin::Generated]);
    expect(app(LeadScoringService::class)->isContactable($company->refresh()))->toBeFalse();

    $jane->email->update(['origin' => EmailOrigin::Crawled]);
    expect(app(LeadScoringService::class)->isContactable($company->refresh()))->toBeTrue();
});

it('passes the Laravel gate on a strong signal even without an ad', function () {
    $company = perfectLead();
    $company->signals()->delete();

    expect(scoreOf($company)->failedGate)->toBeNull();
});

it('runs as the last local pipeline step', function () {
    $company = perfectLead();

    app(AdvanceLeadPipelineAction::class)->run($company);

    expect($company->refresh()->status)->toBe(LeadStatus::Qualified)
        ->and($company->pipeline_step)->toBe(PipelineStep::Done);
    Queue::assertNothingPushed();
});
