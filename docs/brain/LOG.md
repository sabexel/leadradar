# LOG — append-only history

> Claude Code: add one entry per completed task, newest at the **bottom**. Never edit old entries.
>
> Format:
> ```
> ## YYYY-MM-DD — <checklist id> <short title>
> - Done: what was built (files/classes)
> - Tests: what was added, pass/fail
> - Decisions: any new decision (also added to BRAIN.md → Decisions) or "none"
> - Notes / follow-ups: anything the next session must know
> ```

## 2026-11-03 — PLAN Project specification written
- Done: CLAUDE.md, docs/01–10, brain files created. No code yet.
- Tests: n/a
- Decisions: D1–D7 recorded in BRAIN.md
- Notes / follow-ups: start at P0.1. Owner registers API keys for Companies House, Reed, Adzuna,
  MillionVerifier and Brave Search before P1.

## 2026-11-03 — P0.1 Create Laravel app
- Done: Laravel 13 app from the official React starter kit (`dev-main`), copied into the repo root.
  Added `symfony/dom-crawler`, `symfony/css-selector`, Pest 5 + Laravel plugin (`tests/Pest.php`).
  Larastan (level 7) and Pint came with the kit. `docker-compose.yml` runs MySQL 8 on port 3317 and
  creates `leadradar_testing`. `.gitignore` now keeps `CLAUDE.md` tracked and ignores CSV/SQL dumps.
- Tests: starter-kit suite (40 tests) passes on MySQL via Pest; Pint + Larastan clean; `npm run build` ok;
  `php artisan serve` returns 200 on `/` and `/login`.
- Decisions: D8 (starter kit main), D9 (docker MySQL on 3317), D10 (tests on MySQL).
- Notes / follow-ups: local setup = `docker compose up -d`, `cp .env.example .env`, `composer setup`.
  A leftover scaffold copy may exist at `C:\Projects\leadradar_tmp` — safe to delete by hand.

## 2026-11-03 — P0.2 Single-user auth (registration flag)
- Done: `config/leadradar.php` (`registration_enabled` from `REGISTRATION_ENABLED`, default false),
  `App\Http\Middleware\EnsureRegistrationEnabled` (appended to the web group), shared Inertia prop
  `canRegister`; Register/Sign up links hidden on `welcome.tsx` and `auth/login.tsx` when off.
- Tests: `tests/Feature/Auth/RegistrationFlagTest.php` (GET + POST /register → 404, no user created,
  canRegister prop, default false). Starter `RegistrationTest` turns the flag on in `setUp`. 45 passing.
- Decisions: D11 (middleware gate, not removing the Fortify feature), D12 (formatter ignores Markdown).
- Notes / follow-ups: owner creates the first user with `REGISTRATION_ENABLED=true`, then sets it back to false.

## 2026-11-03 — P0.3 Config, .env.example, README skeleton
- Done: `config/services.php` blocks `companies_house`, `reed`, `adzuna`, `millionverifier`, `brave`, `crawler`
  (keys from env, base URLs, timeouts, per-minute limits, crawler UA). `.env.example` lists every API key empty
  plus `REGISTRATION_ENABLED=false` and `CRAWLER_USER_AGENT`. README setup now covers docker MySQL,
  `composer setup`, first-user flow and quality checks.
- Tests: `tests/Unit/EnvExampleTest.php` (all API keys present + empty, registration off, database queue/cache).
  Removed the env-dependent config-default test from P0.2 (covered here). 52 passing.
- Decisions: D13 (per-minute limits in config, daily caps in settings).
- Notes / follow-ups: Adzuna/Brave per-minute values are from free-tier docs as remembered — confirm against
  official docs when implementing P1.2 / P1.6.

## 2026-11-03 — P0.4 Schema: migrations, models, enums, factories
- Done: 11 migrations (companies, people, email_addresses, circular email FKs, signals, crawled_pages,
  pipeline_events, scrape_runs, api_usages, suppressions, settings) with the docs/03 indexes/uniques.
  18 enums in `app/Enums`. Models `Company`, `Person`, `EmailAddress`, `Signal`, `CrawledPage`, `PipelineEvent`,
  `ScrapeRun`, `ApiUsage`, `Suppression`, `Setting` (attributes-style `#[Fillable]`, enum casts, typed relations,
  `Company::founders()`, prunable retention). Factories for all (fake `.test` domains). `SettingsSeeder`,
  `DatabaseSeeder` with local-only demo data.
- Tests: `tests/Feature/Models/*` (defaults, casts, leading zeros, relationships, founders ordering + resigned
  exclusion, lowercased emails, unique constraints, cascade/null-on-delete, settings seed/no-overwrite/fallback,
  pruning). 69 passing; Pint + Larastan clean; `migrate:fresh --seed` works.
- Decisions: D14–D19.
- Notes / follow-ups: `Setting::get()` hits the DB each call — add caching later only if it shows up as slow.

## 2026-11-03 — P0.5 ApiUsage logging + per-service daily caps
- Done: `App\Services\Support\ApiUsageLimiter` (`ensureAvailable`, `record`, `usedToday`, `dailyCap`,
  `remainingToday`, `resumeAt`, `businessDate`), registered as a singleton. Exceptions
  `DailyCapReachedException`, `RateLimitedException`, `ExternalServiceException` in `app/Exceptions`.
  Named `RateLimiter::for()` per service (from `services.*.per_minute`) in `AppServiceProvider` for the
  `RateLimited` job middleware. `config/leadradar.php` gains `timezone` + `caps_resume_at`.
- Tests: `tests/Feature/Services/ApiUsageLimiterTest.php` — usage recorded with cost units, London business
  date, under/at daily cap, per-service/per-day isolation, resume at 06:00 next day, uncapped services,
  per-minute limit, CH rolling 5-min cap, named limiters registered. 83 passing; Pint + Larastan clean.
- Decisions: D20, D21.
- Notes / follow-ups: services (P1) must call `ensureAvailable()` before and `record()` after every call;
  jobs catch `DailyCapReachedException` → `release($e->resumeAt)`.

## 2026-11-03 — P0.6 GitHub Actions CI
- Done: `.github/workflows/ci.yml` (renamed from the starter `tests.yml`): MySQL 8 service with
  `leadradar_testing`, PHP 8.3 + extensions, composer install, `.env` from example, Pint, Larastan,
  `npm ci`, `npm run build`, `npm run check`, `tsc`, Pest. Kept the starter's pinned action SHAs.
- Tests: ran every step locally — all green (83 Pest tests). Not yet run on GitHub: there is no remote.
- Decisions: D22.
- Notes / follow-ups: P0.6 is marked `[~]` until the first green run on GitHub. `.github/dependabot.yml`
  came with the starter kit and is kept.

## 2026-11-03 — P1.1 ReedService + FetchReedJobsJob + FetchReedJobDetailJob
- Done: DTOs `JobAdData`, `JobSearchPage`; base `App\Services\Support\ExternalApiService`;
  `App\Services\JobBoards\ReedService` (`search()`, `getJob()`; Basic auth, `postedByDirectEmployer=true`,
  HTML to plain text, UK dates); jobs `FetchReedJobsJob` (one page; detail job per fresh unknown ad; next-page
  rules) and `FetchReedJobDetailJob` (one detail, then `IngestJobAdAction`) on the `sources` queue with
  `RateLimited('reed')` + `ShouldBeUnique`; trait `InteractsWithServiceLimits`; local `DailyTarget`;
  minimal `IngestJobAdAction`. `services.reed.max_pages = 10`.
- Tests: `tests/Feature/Services/ReedServiceTest.php` (11: params/auth, mapping, take cap, detail, blank job,
  usage logged, 429, 5xx retried once, 4xx not retried, malformed JSON, missing key, daily cap) and
  `tests/Feature/Jobs/ReedJobsTest.php` (13: next page dispatched / not dispatched for empty, stale, last page,
  max pages; known ads skipped; target reached = no call; 429 = released; cap = released to 06:00; idempotent
  detail ingest + run counter; blank job; queue/unique/retry settings). Fixtures in `tests/Fixtures/reed/`
  (invented companies). 107 passing; Pint + Larastan clean.
- Decisions: D23–D29.
- Notes / follow-ups: verify Reed JSON keys against a live response (D23). Reed's rate limit is undocumented;
  self-limit stays 60/min.

## 2026-11-04 — P0.6 fix: CI PHP version + repo published
- Done: pushed to the public repo https://github.com/sabexel/leadradar (first push hit transient GitHub 500s;
  succeeded incrementally). First CI run failed at `composer install`: the lockfile needs PHP 8.4.1+.
  Now `php: ^8.4`, `config.platform.php = 8.4.1`, CI `php-version: '8.4'`; README, CLAUDE.md, docs/08 updated.
  Pre-publish scan: no `.env`, keys, dumps or prospect data in any commit.
- Tests: unchanged locally (green). P0.6 stays `[~]` until the rerun on GitHub is green.
- Decisions: D30, D34.
- Notes / follow-ups: Hostinger must run PHP 8.4+.

## 2026-11-04 — P1.2 AdzunaService + FetchAdzunaJobsJob
- Done: `App\Services\JobBoards\AdzunaService::search()` → `JobSearchPage` (HTML stripped from title/description,
  ISO `created` dates, ads without a company dropped); `FetchAdzunaJobsJob` (one page; ingests fresh unknown ads
  via `IngestJobAdAction`; next page while non-empty, fresh, `page × 50 < count`, `page < max_pages`). Shared
  `JobSearchPage::hasMoreAfter()` now used by Reed too. `services.adzuna.max_pages = 10`; setting
  `cap.adzuna.per_day = 200`. Credential redaction in `ExternalApiService` connection errors.
- Tests: `tests/Feature/Services/AdzunaServiceTest.php` (7) and `tests/Feature/Jobs/AdzunaJobsTest.php` (9):
  request params, mapping, per-page cap, usage endpoint label, key redaction, 429, missing creds, daily cap;
  ingest + run counter, idempotency, max-age filter, stop conditions (empty, last page, max pages, target),
  cap release. Fixtures `tests/Fixtures/adzuna/`. 123 passing; Pint + Larastan clean.
- Decisions: D31–D33.
- Notes / follow-ups: recruiter ads are still stored as Signals until P1.3 adds `RecruiterDetector`.

## 2026-11-04 — P0.6 CI green on GitHub
- Done: second CI failure was `vp check` formatting `tests/Fixtures/adzuna/search.json` (formatted). Run
  41811595738 on `ef7862c` is green: composer install (PHP 8.4), Pint, Larastan, npm build, vp check, tsc, Pest
  on MySQL. P0.6 ticked.
- Tests: all green on GitHub and locally.
- Decisions: none.
- Notes / follow-ups: run `npx vp check` locally before every push — it also formats JSON fixtures.
  GitHub returned intermittent HTTP 500s on push today; a plain retry works.

## 2026-11-04 — P1.3 IngestJobAdAction (recruiter filter, dedupe, company creation)
- Done: local `App\Services\Dedupe\CompanyMatcher` (`normalise`, Jaro-Winkler `similarity`, `domainOf`),
  `App\Services\Classification\RecruiterDetector` (name patterns, "on behalf of a client" ad text, SIC 78xxx),
  `App\Services\Support\SuppressionList`; first cut of `AdvanceLeadPipelineAction` (finished guard + suppression
  gate); `Company::recordEvent()`. `IngestJobAdAction` now: recruiter skip → lock + transaction → match on
  normalised name or create `discovered` company → Signal upsert linked to it → run counters
  (`signals_found`, `companies_created`) → advance new companies only.
- Tests: `tests/Unit/Dedupe/CompanyMatcherTest.php`, `tests/Unit/Classification/RecruiterDetectorTest.php`,
  `tests/Feature/Actions/IngestJobAdActionTest.php` (same ad twice → 1 signal + 1 company; name variants and
  sources merge; different employers stay apart; recruiter → nothing stored; existing rejected company untouched),
  `tests/Feature/Actions/AdvanceLeadPipelineActionTest.php` (suppress by domain/number/email, event context,
  finished statuses untouched). Adzuna job tests updated: recruiter fixture ad now skipped. 191 passing;
  Pint, Larastan, vp check clean.
- Decisions: D35–D39. Known issue added ("people" keyword).
- Notes / follow-ups: P1.4 wires the registry steps into `AdvanceLeadPipelineAction`.

## 2026-11-04 — P1.4 CompaniesHouseService (search, profile, officers) + jobs
- Done: DTOs `CompanySearchResult`, `CompanyProfile`, `Officer` (no DOB/nationality/address), `RegistryMatch` +
  enum `RegistryMatchOutcome`; `App\Services\CompaniesHouse\CompaniesHouseService` (`searchCompanies`,
  `getProfile`, `getOfficers`, `parseName`); local `RegistryMatcher`; `ApplyCompaniesHouseResultAction`
  (`applySearch` incl. duplicate-number merge, `applyProfile` gates, `applyOfficers` founders);
  jobs `SearchCompaniesHouseJob`, `FetchCompanyProfileJob`, `FetchCompanyOfficersJob` on the `registry` queue
  with `RateLimited('companies_house')`; trait `FailsCompanyStep`; `Company::reject()`, `isFinished()`,
  `isAwaiting()`; registry steps wired into `AdvanceLeadPipelineAction`; `ExternalApiService` 404 option.
- Tests: `tests/Feature/Services/CompaniesHouseServiceTest.php` (10) and
  `tests/Feature/Jobs/CompaniesHouseJobsTest.php` (25): exact match, ambiguous (rejected + candidates), town
  tie-break, dissolved (rejected), no match, duplicate-number merge, suppressed number, profile stored, LLP/PLC
  accepted, gates (dissolved, liquidation, liquidated, unlimited, LP, dormant), 404, founders ordering, resigned
  excluded from Founder 1/2, secretaries/corporate excluded, LLP members parsed, no DOB stored, idempotent
  officers, step guard, failure → error, ingest queues the search, non-GB skipped. Action tests updated for the
  queued search. 226 passing; Pint, Larastan, vp check, tsc clean.
- Decisions: D40–D47.
- Notes / follow-ups: companies now stop at `classify` until P1.5 wires it in.

## 2026-11-04 — P1.5 CompanyClassifier + RecruiterDetector + OffshoreSignalDetector (text)
- Done: enum `SignalWeight`; DTOs `DetectedSignal`, `ClassificationInput`, `Classification`;
  local `App\Services\Crawler\OffshoreSignalDetector` (`detectInText`, `detectOfficersAbroad`, `combine`);
  local `App\Services\Classification\CompanyClassifier`; `ClassifyCompanyAction` (stores `company_type` +
  `offshore_evidence`, rejects recruiter/offshore, records the classify event). `AdvanceLeadPipelineAction`
  now loops through local steps; `classify` → `find_website`. (`RecruiterDetector` came in P1.3.)
- Tests: `tests/Unit/Classification/OffshoreSignalDetectorTest.php`, `CompanyClassifierTest.php` (agency,
  explicit agency, direct client, recruiter by name/SIC, offshore ×3, one medium signal is not offshore, officers
  abroad alone is not offshore, unknown ×2) with text fixtures in `tests/Fixtures/classification/`;
  `tests/Feature/Actions/ClassifyStepTest.php` (pipeline wiring). CH officers test updated (now continues to
  `find_website`). 253 passing; Pint, Larastan, vp check, tsc clean.
- Decisions: D48–D51. Known issue added ("studio" names).
- Notes / follow-ups: companies now stop at `find_website` until P1.6.

## 2026-11-04 — P1.6 BraveSearchService::findCompanyWebsite() + FindCompanyWebsiteJob
- Done: DTO `SearchResult`; `App\Services\Search\BraveSearchService` (`findCompanyWebsite`); local
  `WebsiteSelector` (blocklist + significant-token match); `ApplyWebsiteResultAction` (search result or known
  website → `website`/`domain`/`crawl`, one-domain-one-lead guard); `FindCompanyWebsiteJob` (`web` queue,
  `RateLimited('brave')`); `find_website` wired into `AdvanceLeadPipelineAction` (local when the site is known).
  Blocklist in `config/services.php`.
- Tests: `tests/Unit/Search/WebsiteSelectorTest.php` (tokens, blocklist incl. subdomains/gov.uk, Endole first →
  skipped and real site chosen, domain-only match, unrelated rejected, no partial-word match) and
  `tests/Feature/Jobs/FindCompanyWebsiteJobTest.php` (query/headers, directory-first fixture, no result →
  `no_website`, suppressed domain, domain owned by another lead, cap release, known website skips the search,
  queued by the pipeline, step guard). Classify + CH tests updated (companies without a website now queue the
  search). 279 passing; Pint, Larastan, vp check, tsc clean.
- Decisions: D52–D55. Brave open question updated.
- Notes / follow-ups: companies now stop at `crawl` until P1.7.
