<?php

declare(strict_types=1);

use App\Actions\ApplyVerificationResultAction;
use App\DTOs\Verification\EmailVerificationResult;
use App\Enums\EmailKind;
use App\Enums\EmailVerificationStatus;
use App\Enums\ExternalService;
use App\Enums\LeadStatus;
use App\Enums\OutreachStatus;
use App\Enums\RejectReason;
use App\Enums\ScrapeRunStatus;
use App\Enums\SignalSource;
use App\Models\ApiUsage;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\ScrapeRun;
use App\Models\Setting;
use App\Models\Signal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // 14:00 London on a weekday (BST: 13:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-07-15 13:00:00', 'UTC'));
    $this->actingAs(User::factory()->create());
});

it('redirects guests to the login page', function () {
    auth()->logout();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('renders an empty dashboard without errors', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('range.preset', 'today')
            ->where('range.from', '2026-07-15')
            ->where('range.to', '2026-07-15')
            ->where('stats.discovered', 0)
            ->where('stats.averageScore', null)
            ->has('qualifiedPerDay.days', 30)
            ->where('qualifiedPerDay.target', 50)
            ->has('funnel', 5)
            ->where('rejectReasons', [])
            ->has('scoreDistribution', 11)
            ->where('sources', [])
            ->where('runs', [])
            ->has('apiUsage', count(ExternalService::cases()))
            ->where('credits', null));
});

it('counts the stat cards for the selected range', function () {
    // Discovered today (cohort) …
    Company::factory()->qualified(8.0)->create();
    Company::factory()->qualified(6.0)->create();
    Company::factory()->rejected(RejectReason::Recruiter)->create();
    Company::factory()->create(['status' => LeadStatus::Enriching]);
    Company::factory()->create(['status' => LeadStatus::Discovered]);
    Company::factory()->create(['status' => LeadStatus::Error]);
    $contacted = Company::factory()->create(['outreach_status' => OutreachStatus::Contacted, 'contacted_at' => now()]);
    Company::factory()->create(['outreach_status' => OutreachStatus::Replied, 'contacted_at' => now()]);

    // … and things outside the range that must not count.
    $old = now()->subDays(3);
    Company::factory()->qualified(10.0)->create(['created_at' => $old, 'qualified_at' => $old]);
    Company::factory()->rejected()->create(['created_at' => $old]);
    Signal::factory()->create(['created_at' => $old]);

    Signal::factory()->count(3)->create();
    Signal::factory()->create(['source' => SignalSource::Adzuna]);
    EmailAddress::factory()->for($contacted)->create(['kind' => EmailKind::Person, 'verification_status' => EmailVerificationStatus::Ok, 'verified_at' => now()]);
    EmailAddress::factory()->for($contacted)->create(['kind' => EmailKind::Contact, 'verification_status' => EmailVerificationStatus::Ok, 'verified_at' => now()]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('stats.scraped', 4)
        ->where('stats.discovered', 8)
        ->where('stats.qualified', 2)
        ->where('stats.rejected', 1)
        ->where('stats.inProgress', 4) // enriching + discovered + the two contacted (discovered by default)
        ->where('stats.errors', 1)
        ->where('stats.averageScore', 7)
        ->where('stats.verifiedFounderEmails', 1)
        ->where('stats.contacted', 2)
        ->where('stats.replied', 1)
        ->where('sources', [
            ['source' => 'reed', 'label' => 'Reed', 'count' => 3],
            ['source' => 'adzuna', 'label' => 'Adzuna', 'count' => 1],
        ]));

    // The 7-day range takes in the older rows.
    $this->get(route('dashboard', ['range' => '7d']))->assertInertia(fn (Assert $page) => $page
        ->where('range.from', '2026-07-09')
        ->where('stats.scraped', 5)
        ->where('stats.qualified', 3)
        ->where('stats.rejected', 2)
        ->where('stats.averageScore', 8));
});

it('uses London days, not UTC days', function () {
    // 23:30 London on the 14th is 22:30 UTC — yesterday in London.
    Company::factory()->qualified()->create(['qualified_at' => CarbonImmutable::parse('2026-07-14 22:30:00', 'UTC')]);
    // 00:30 London on the 15th is 23:30 UTC on the 14th — today in London.
    Company::factory()->qualified()->create(['qualified_at' => CarbonImmutable::parse('2026-07-14 23:30:00', 'UTC')]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('stats.qualified', 1)
        ->where('qualifiedPerDay.days.28', ['date' => '2026-07-14', 'qualified' => 1])
        ->where('qualifiedPerDay.days.29', ['date' => '2026-07-15', 'qualified' => 1]));
});

it('accepts a custom range and ignores a broken one', function () {
    $this->get(route('dashboard', ['range' => 'custom', 'from' => '2026-07-10', 'to' => '2026-07-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('range.preset', 'custom')
            ->where('range.from', '2026-07-01')
            ->where('range.to', '2026-07-10'));

    $this->get(route('dashboard', ['range' => 'custom', 'from' => '2026-02-31', 'to' => 'nope']))
        ->assertInertia(fn (Assert $page) => $page->where('range.preset', 'today'));

    $this->get(route('dashboard', ['range' => 'bogus']))
        ->assertInertia(fn (Assert $page) => $page->where('range.preset', 'today'));
});

it('builds the funnel, reject reasons and score histogram from the discovered cohort', function () {
    $verified = Company::factory()->qualified(9.5)->create();
    EmailAddress::factory()->for($verified)->create(['kind' => EmailKind::Person, 'verification_status' => EmailVerificationStatus::Ok]);
    Company::factory()->rejected(RejectReason::BelowThreshold)->create(['score' => 4.2]);
    Company::factory()->rejected(RejectReason::BelowThreshold)->create(['score' => 10.0]);
    Company::factory()->rejected(RejectReason::NoWebsite)->create(['domain' => null, 'website' => null]);
    Company::factory()->discovered()->create();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('funnel', [
            ['stage' => 'discovered', 'label' => 'Discovered', 'count' => 5],
            ['stage' => 'registry', 'label' => 'Registry ok', 'count' => 4],
            ['stage' => 'website', 'label' => 'Website found', 'count' => 3],
            ['stage' => 'email', 'label' => 'Email verified', 'count' => 1],
            ['stage' => 'qualified', 'label' => 'Qualified', 'count' => 1],
        ])
        ->where('rejectReasons', [
            ['reason' => 'below_threshold', 'label' => 'Below threshold', 'count' => 2],
            ['reason' => 'no_website', 'label' => 'No website', 'count' => 1],
        ])
        ->where('scoreDistribution.4.count', 1)
        ->where('scoreDistribution.9.count', 1)
        ->where('scoreDistribution.10.count', 1)
        ->where('scoreDistribution.0.count', 0));
});

it('shows the last five runs, today\'s API usage against caps and the credit balance', function () {
    foreach (range(1, 6) as $i) {
        ScrapeRun::factory()->create([
            'started_at' => now()->subHours(7 - $i),
            'finished_at' => now()->subHours(7 - $i)->addMinutes(12),
            'status' => ScrapeRunStatus::Finished,
            'signals_found' => $i,
        ]);
    }

    ApiUsage::factory()->count(3)->create(['service' => ExternalService::Brave, 'date' => '2026-07-15']);
    ApiUsage::factory()->create(['service' => ExternalService::Brave, 'date' => '2026-07-15', 'status_code' => 500]);
    ApiUsage::factory()->create(['service' => ExternalService::Brave, 'date' => '2026-07-14']);
    Setting::put('cap.brave.per_day', 120);

    app(ApplyVerificationResultAction::class)->apply(
        EmailAddress::factory()->create(),
        new EmailVerificationResult(email: 'jane@example-digital.test', status: EmailVerificationStatus::Ok, subresult: null, quality: 'good', creditsLeft: 4321, raw: ['result' => 'ok']),
    );

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->has('runs', 5)
        ->where('runs.0.signalsFound', 6)
        ->where('runs.0.durationSeconds', 720)
        ->where('runs.0.status', 'finished')
        ->where('apiUsage', fn ($rows) => collect($rows)->firstWhere('service', 'brave') == [ // JSON turns 4.0 into 4
            'service' => 'brave', 'label' => 'Brave Search', 'calls' => 4, 'used' => 4.0, 'cap' => 120.0, 'errors' => 1,
        ])
        ->where('credits.credits', 4321));
});
