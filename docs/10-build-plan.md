# 10 — Build plan (phased, with acceptance criteria)

Work top to bottom. One checklist item = one commit (or a few). After each item, update the brain (`CLAUDE.md §2`).
**Timebox:** P0+P1 ≈ 3 working days · P2 ≈ 2 days · P3 ≈ 0.5 day. Do **not** start P4 until P3 is live and
producing leads for a week.

---

## P0 — Foundation

**P0.1 Create app.** `laravel new leadradar` with the React starter kit; MySQL; add Pest, Pint, Larastan,
`symfony/dom-crawler`, `symfony/css-selector`.
✅ `composer run dev` serves the starter dashboard; `./vendor/bin/pest` passes.

**P0.2 Auth.** Keep starter-kit auth. Add `REGISTRATION_ENABLED` env flag (default false) — registration route
only works when true, so the owner creates the first user then turns it off.
✅ Test: registration returns 404 when flag is false.

**P0.3 Config.** `.env.example` with every key (empty values); `config/services.php` blocks for
`companies_house`, `reed`, `adzuna`, `millionverifier`, `brave`, `crawler`; README skeleton (purpose, stack, setup,
compliance summary).
✅ `php artisan config:show services` lists all blocks; no secrets in git.

**P0.4 Schema.** Migrations, models, casts, relationships, enums and factories for every table in `docs/03`.
`SettingsSeeder` with defaults.
✅ `migrate:fresh --seed` works; factory-based model tests for relationships.

**P0.5 Usage & caps.** `ApiUsage` model, `ApiUsageLimiter` (daily caps from settings + `RateLimiter::for()` per
service in `AppServiceProvider`), `DailyCapReachedException`, `RateLimitedException`.
✅ Unit tests: cap reached throws; usage recorded with cost units.

**P0.6 CI.** `.github/workflows/ci.yml` (Pint, Larastan, Pest, `npm run build`).
✅ Green on GitHub.

## P1 — Core pipeline (UK, job-ad signal)

**P1.1 Reed.** `ReedService::search()`, `getJob()`; DTO `JobAdData`; jobs `FetchReedJobsJob` (one page, dispatches
next page per rules in `05`), `FetchReedJobDetailJob`.
✅ Fixture tests for both endpoints; job test asserts next page dispatched / not dispatched.

**P1.2 Adzuna.** `AdzunaService::search()` → `JobAdData`; `FetchAdzunaJobsJob`.
✅ Fixture tests; pagination stop conditions tested.

**P1.3 Ingest.** `IngestJobAdAction`: `RecruiterDetector` skip, `Signal` upsert (unique source+external_id),
`CompanyMatcher` dedupe, suppression check, create Company (`discovered`), call `AdvanceLeadPipelineAction`.
✅ Same ad ingested twice → 1 signal, 1 company. Recruiter employer → no company.

**P1.4 Companies House.** Service (`searchCompanies`, `getProfile`, `getOfficers`) + DTOs + 3 jobs +
`ApplyCompaniesHouseResultAction`. Matching rules from `04`. Don't store DOB.
✅ Fixtures: exact match, ambiguous match (rejected), dissolved (rejected), LLP members parsed, resigned
directors excluded from Founder 1/2.

**P1.5 Classifier.** `CompanyClassifier` + `RecruiterDetector` + `OffshoreSignalDetector` (text part).
✅ Unit tests per type with fixture inputs.

**P1.6 Website lookup.** `BraveSearchService::findCompanyWebsite()` + `FindCompanyWebsiteJob`; directory blocklist.
✅ Fixture: first result is Endole → skipped, second is real site → chosen. No result → `no_website`.

**P1.7 Crawler.** `RobotsTxtGuard`, `WebsiteCrawlerService::fetchPage()`, `EmailExtractor`,
`LaravelSignalDetector`, `OffshoreSignalDetector` (page part), `CrawlCompanyWebsiteJob` (one page per job,
queues next candidate page up to cap), `ApplyCrawlResultAction`.
✅ HTML fixtures: mailto + obfuscated emails, off-domain emails ignored, Laravel cookie detected, robots disallow
respected, page cap respected.

**P1.8 Email patterns.** `EmailPatternService::candidates()` + `BuildFounderEmailCandidatesAction`.
✅ Order of patterns tested; existing confirmed pattern tried first.

**P1.9 Verification.** `MillionVerifierService::verify()` + `VerifyEmailJob`; stop per person at first `ok`;
skip re-verification within 90 days; cap enforced.
✅ Fixtures for each result type; cap reached → job released, not failed.

**P1.10 LinkedIn lookup.** `BraveSearchService::findLinkedInProfile()` + `FindLinkedInProfileJob`.
✅ Only `linkedin.com/in/` URLs whose title contains first + last name accepted.

**P1.11 Scoring.** `LeadScoringService` + `QualifyCompanyAction` exactly per `06`.
✅ All unit/boundary tests from `06` pass.

**P1.12 State machine.** `AdvanceLeadPipelineAction` wiring every step and `PipelineEvent` logging; retry-from-step.
✅ Feature test: one fake company goes `discovered → qualified` with all HTTP faked; one fails at each gate.

**P1.13 Scheduler.** `leadradar:discover`, `leadradar:close-runs`, `leadradar:rescore`; schedule per `08`;
target-reached stop.
✅ Test: when today's qualified ≥ target, discover exits without dispatching.

**P1.14 Suppression.** `Suppression` checks in ingest and before any paid call; `ImportContactedCsvAction`.
✅ Importing the owner's sheet export suppresses every domain in it.

## P2 — Dashboard

**P2.1** Dashboard page per `07` (cards, charts — use Recharts via shadcn charts).
**P2.2** Leads index with all filters + URL query state + bulk actions.
**P2.3** Lead detail with score breakdown, evidence, founders, timeline, retry buttons.
**P2.4** Outreach status changes (contacted sets `contacted_at`; not interested also adds a suppression).
**P2.5** CSV export in the outreach-sheet order (`07`).
**P2.6** Runs & API usage page; Settings page with key status + test connection.
**P2.7** Imports page (domains/CSV → pipeline; suppression import with column mapping).
✅ For each: feature tests on controllers (Inertia assertions), a manual check in the browser.

## P3 — Deploy

**P3.1** Hostinger setup per `08`; `deploy.yml`; cron; first production run.
**P3.2** Failed-jobs daily email; smoke test checklist in README.
✅ A scheduled run completes in production and qualified leads appear on the dashboard.

## P4 — Extensions (later)

P4.1 Laravel Partners directory source · P4.2 NL (KVK) / US (OpenCorporates) registries ·
P4.3 Google Sheets push · P4.4 Opening-line generator (one specific first line per lead, from the signal and evidence).

---

## Definition of done (every item)

- Code follows `CLAUDE.md` hard rules (services own HTTP, jobs = one outbound call).
- Tests added and passing; Pint + Larastan clean.
- No secrets or real prospect data committed.
- `BRAIN.md` ticked + status updated; `LOG.md` entry added; decision recorded if any.
