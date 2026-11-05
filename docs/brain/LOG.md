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

## 2026-11-04 — P1.7 WebsiteCrawlerService + local analysers + CrawlCompanyWebsiteJob
- Done: DTOs `CrawlResult`, `ExtractedEmail`; `RobotsRules` + `RobotsTxtGuard` (parse, cache, per-domain
  clock); local `EmailExtractor`, `LaravelSignalDetector`, `CrawlPlanner` (page types, candidate links, next URL,
  sitemap fallback, cap); `WebsiteCrawlerService` (`fetchRobotsTxt`, `fetchPage`; UA from config, no HTML kept);
  `ApplyCrawlResultAction` (`apply` per page, `finish` = crawl event + second classification → 
  `build_email_candidates`); `CrawlCompanyWebsiteJob` (`web` queue, `WithoutOverlapping`); `crawl` wired into
  `AdvanceLeadPipelineAction`. `CompanyClassifier::agencyTerms()`; `ClassificationInput::siteOffshoreSignals`;
  `ExternalApiService::get(allowErrors)`.
- Tests: `tests/Unit/Crawler/RobotsTxtGuardTest.php`, `tests/Unit/Crawler/PageAnalysersTest.php` (mailto + plain +
  obfuscated emails, off-domain ignored, script emails ignored, no-reply dropped, Laravel partner/cookie/Livewire/
  Inertia/framework list, candidate links, page types) and `tests/Feature/Jobs/CrawlCompanyWebsiteJobTest.php`
  (robots first, page order, UA, page cap, robots disallow-all skip, our-bot rules + Crawl-delay, politeness
  release, emails → founders + inboxes, Laravel evidence, no HTML stored, offshore rejection after crawl, sitemap
  fallback, unreachable inner page vs home, direct-client re-classification). HTML/robots fixtures in
  `tests/Fixtures/html`, `tests/Fixtures/robots`. 327 passing; Pint, Larastan, vp check, tsc clean.
- Decisions: D56–D62. Known issue added (robots 5xx).
- Notes / follow-ups: owner to publish https://webwayout.co.uk/bot. Companies now stop at
  `build_email_candidates` until P1.8.

## 2026-11-04 — P1.8 EmailPatternService + BuildFounderEmailCandidatesAction
- Done: local `App\Services\Email\EmailPatternService` (`candidates`, `patternOf`, `PATTERNS`);
  `BuildFounderEmailCandidatesAction` (learns the company convention, generates rows for Founder 1/2, skips
  founders with a crawled/verified address, records the event, → `verify_emails`); `build_email_candidates`
  wired into `AdvanceLeadPipelineAction` as a local step.
- Tests: `tests/Unit/Email/EmailPatternServiceTest.php` (pattern order, confirmed pattern first, unknown
  pattern ignored, name normalisation, preferred name, missing names, pattern detection) and
  `tests/Feature/Actions/BuildFounderEmailCandidatesActionTest.php` (14 rows for two founders in try-order,
  convention from crawled and from verified addresses, skip when found on site, only Founder 1/2, idempotent,
  pipeline step, suppressed generated address). Crawl tests now end at `verify_emails`. 350 passing; Pint,
  Larastan, vp check clean.
- Decisions: D63–D66.
- Notes / follow-ups: companies now stop at `verify_emails` until P1.9.

## 2026-11-04 — P1.9 MillionVerifierService + VerifyEmailJob
- Done: DTO `EmailVerificationResult`; `App\Services\Verification\MillionVerifierService` (`verify`, `credits`);
  local `VerificationPlanner` (next address worth a credit); `ApplyVerificationResultAction` (`apply` per result,
  `finish` = best address per founder + event → `find_linkedin`); `VerifyEmailJob` (`verify` queue,
  `RateLimited('millionverifier')`); `verify_emails` wired into `AdvanceLeadPipelineAction`.
- Tests: `tests/Feature/Services/MillionVerifierServiceTest.php` (params, every result value, 1 credit logged,
  bad key, empty balance, cap, free credits call) and `tests/Feature/Jobs/VerifyEmailJobTest.php` (stop at first
  ok per founder, crawled first, inboxes, catch-all domain stop, all invalid → no address, 90-day window,
  cap → released not failed, empty balance → released, no second credit on re-run, nothing to verify, queue).
  Fixtures `tests/Fixtures/millionverifier/`. 373 passing; Pint, Larastan, vp check, tsc clean.
- Decisions: D67–D70.
- Notes / follow-ups: confirm MillionVerifier result values/error texts with the demo key (D67). Companies now
  stop at `find_linkedin` until P1.10.

## 2026-11-04 — P1.10 BraveSearchService::findLinkedInProfile() + FindLinkedInProfileJob
- Done: local `LinkedInProfileSelector`; `BraveSearchService::findLinkedInProfile()`; `ApplyLinkedInResultAction`
  (next founder, store URL + source, finish → `qualify`); `FindLinkedInProfileJob` (`web` queue,
  `RateLimited('brave')`); migration `people.linkedin_checked_at`; `find_linkedin` wired into the pipeline.
- Tests: `tests/Unit/Search/LinkedInProfileSelectorTest.php`, `tests/Feature/Jobs/FindLinkedInProfileJobTest.php`
  (query format, only /in/ URLs with first + last name, wrong-surname/company/post results rejected, canonical URL
  + source stored, linkedin.com never requested, 30-day skip, cap release, no double query). Verify test updated
  (no founders → `qualify`). All passing locally; Pint, Larastan, vp check, tsc clean. CI not yet checked.
- Decisions: D71.
- Notes / follow-ups: companies now stop at `qualify` until P1.11.

## 2026-11-04 — P1.11 LeadScoringService + QualifyCompanyAction
- Done: DTO `LeadScore`; local `App\Services\Scoring\LeadScoringService` (docs/06 gates in table order +
  five-line 0–10 score with per-item breakdown); `QualifyCompanyAction` (score + gates + `qualify_threshold` →
  qualified / rejected(reason), event, `done`); `qualify` wired into `AdvanceLeadPipelineAction` as the last
  local step. Company evidence docblock now includes `weight`.
- Tests: `tests/Feature/Scoring/LeadScoringTest.php` (48): perfect lead = 10.0, every criterion line,
  Laravel cap, job-board-only ads, timing boundaries 7/8, 14/15, 30/31 days, newest ad wins, fit (types, accounts
  categories, 2-year boundary), reach (ok/catch_all/other, LinkedIn, Founder 2), inboxes, threshold 9.5 vs 9.6 and
  the default 6.0 vs 5.5, stored breakdown + qualified_at kept on rescore, one fixture per reject reason (11),
  crawled vs generated catch_all, Laravel gate without an ad, pipeline step. Three pipeline tests now end at
  `done`. 447 passing; Pint, Larastan clean.
- Decisions: D72–D75.
- Notes / follow-ups: P1.12 adds retry-from-step and the end-to-end pipeline test.

## 2026-11-04 — P1.12 AdvanceLeadPipelineAction complete + retry-from-step
- Done: every step is wired (registry → classify → website → crawl → candidates → verify → LinkedIn → qualify
  → done) and every transition writes a PipelineEvent; `IngestJobAdAction` adds the "Discovered from…" event;
  `RetryPipelineStepAction` (retry an errored/rejected company from a step, with per-step resets);
  `RobotsTxtGuard::forget()`.
- Tests: `tests/Feature/Pipeline/FullPipelineTest.php` (17): one Reed ad → qualified 10.0 with every external
  system faked by one router (Reed, CH, Brave, company site, MillionVerifier); timeline has all 10 steps in
  order; only official APIs + the company's own site are called and every call is logged in `api_usages`;
  nine gate failures end-to-end (not on registry, not active, wrong legal form, dormant, recruiter, offshore, no
  website, no valid email, no Laravel evidence); suppressed domain stops before the crawl; retry from a failed
  step; retry crawl re-fetches robots + re-crawls; qualified/suppressed cannot be retried. 464 passing; Pint,
  Larastan, vp check, tsc clean.
- Decisions: D76, D77. Known issue (robots 5xx) updated.
- Notes / follow-ups: P1.13 adds the scheduler/commands that start runs.

## 2026-11-04 — P1.13 Scheduler + leadradar:discover / close-runs / rescore
- Done: `StartDiscoveryRunAction` + `leadradar:discover [--manual]`; `CloseScrapeRunsAction` +
  `leadradar:close-runs`; `leadradar:rescore` (local, qualified + near misses); `routes/console.php` schedule per
  docs/08 (starter `inspire` removed). Commands use Laravel 13 `#[Signature]` / `#[Description]` attributes.
- Tests: `tests/Feature/Console/SchedulerCommandsTest.php` (11): target reached → run stopped and nothing
  dispatched; yesterday's leads don't count; page 1 per keyword on both boards tied to the run; manual trigger;
  missing key skips a board / nothing to search fails the run; non-UK countries search nothing; no second
  concurrent run; close-runs counts + waits for queued work + 20 h timeout; rescore scope + near miss promoted +
  qualified_at kept; schedule expressions/timezone/options. 475 passing; Pint, Larastan, vp check, tsc clean.
- Decisions: D78–D81.
- Notes / follow-ups: on Hostinger this needs the single cron line from docs/08 (`schedule:run` every minute).

## 2026-11-05 — P1.14 Suppression import + checks before paid calls (P1 complete)
- Done: DTO `SuppressionImportResult`; `ImportContactedCsvAction` (header detection or explicit mapping,
  spreadsheet clean-up, domains/emails/company numbers → `suppressions`, existing leads suppressed);
  `leadradar:import-contacted` command; `FailsCompanyStep::stopIfSuppressed()` used by `FindCompanyWebsiteJob`,
  `VerifyEmailJob`, `FindLinkedInProfileJob` before their paid call. (Ingest/pipeline suppression gates came in
  P1.3–P1.6.)
- Tests: `tests/Feature/Imports/ImportContactedCsvTest.php` (10): the invented sheet export suppresses every
  domain, email and company number in it (BOM, `="…"`, lost zero, SC number, two emails in a cell, blank rows,
  duplicate row); job-board/LinkedIn/free-mail domains never suppressed; idempotent; semicolon file + explicit
  mapping; no usable column / unknown mapped column refused; existing leads suppressed + marked contacted;
  command with reason + failures; no Brave / MillionVerifier call for a company suppressed after queueing.
  Fixtures `tests/Fixtures/imports/`. 485 passing; Pint, Larastan, vp check, tsc clean.
- Decisions: D82–D84.
- Notes / follow-ups: Docker Desktop had stopped (tests could not reach MySQL on 3317) — started it again; the
  `leadradar-mysql` volume was intact. P1 is complete; P2 (dashboard) is next.

## 2026-11-05 — P2.1 Dashboard page
- Done: `DashboardController` (invokable, replaces the starter `Route::inertia`) → `App\Queries\DashboardQuery`
  with DTO `DateRange` (today / 7d / 30d / custom, London days). Props: stat cards, qualified per day (30 days) vs
  target, funnel, reject reasons, score histogram 0–10, source split, last 5 runs, API usage today vs caps,
  MillionVerifier balance. `label()` on `RejectReason`, `SignalSource`, `ExternalService`.
  `ApplyVerificationResultAction` stores the returned credit balance. React page `resources/js/pages/dashboard.tsx`
  (+ `types/dashboard.ts`) with shadcn cards, toggle group range picker, Recharts via the shadcn `chart` component.
- Tests: `tests/Feature/Dashboard/DashboardPageTest.php` (7) replaces the starter `DashboardTest`: guest redirect;
  empty dashboard; cards for today vs 7 days; London-day boundaries (BST); custom/broken ranges; funnel + reject
  reasons + histogram; runs + API usage vs cap + credits. 490 passing; Pint, Larastan, vp check, tsc, vp build clean.
  Manual browser check on `php artisan serve` with the local demo seed: all cards, charts, panels and the custom
  range (query string) work; no console errors. Temporary runs/API-usage rows were removed afterwards.
- Decisions: D85–D87.
- Notes / follow-ups: sidebar still has only Dashboard (and the starter's Repository/Documentation footer links) —
  add Leads/Imports/Runs/Settings as those pages land in P2.2–P2.7.

## 2026-11-05 — P2.2 Leads index
- Done: `CompanyController@index` + `@bulk`, `App\Queries\CompanyIndexQuery` (filters → normalised filter state,
  filtered/sorted query, paginator, row shape), `BulkCompanyActionRequest`, actions `UpdateOutreachStatusAction`
  and `SuppressCompanyAction`. Routes `companies.index`, `companies.bulk`. React page
  `resources/js/pages/companies/index.tsx` (search with debounce, multi-selects, country/source/reject-reason
  selects, score slider + Excellent/Good/Near miss chips, qualified date range, verified/LinkedIn toggles, sortable
  headers, bulk bar with confirm on suppressing actions, pagination + per-page), `components/lead-badges.tsx`
  (score/verification/status badges for reuse in P2.3), `types/companies.ts`; shadcn `slider` + `table`; sidebar
  "Leads".
- Tests: `tests/Feature/Companies/CompanyIndexTest.php` (14): guest redirect; columns + default score order with
  unscored last; status/outreach/type/score filters (incl. comma lists, bogus values ignored); country, source,
  qualified date (London-day boundary), reject reason, invalid date; verified email + LinkedIn toggles; search on
  name/domain/founder/email with LIKE wildcards escaped; sorts + pagination keeping the query string; bulk
  contacted (first contact date kept), not interested (+ domain / company-number suppression), suppress domain
  (skips no-domain), retry from step (skips qualified, dispatches the crawl job), validation. 504 passing; Pint,
  Larastan, vp check, tsc, vp build clean.
  Manual browser check with the local demo seed: table, Excellent chip, search, select + "Mark contacted" toast;
  no console errors. The demo lead was reset afterwards.
- Decisions: D88–D90.
- Notes / follow-ups: company names are not links yet — the detail page is P2.3. "Select all matching" across
  pages is not built (page selection only).

## 2026-11-05 — P2.3 Lead detail page (+ P2.4 outreach status)
- Done: `CompanyController@show/update/retry/reverify`, `App\Queries\CompanyDetailQuery`, `UpdateCompanyRequest`,
  `ReverifyEmailJob`, `ApplyReverificationAction`; `ApplyVerificationResultAction::bestFor()` extracted. Routes
  `companies.show`, `companies.update` (PATCH notes / outreach_status), `companies.retry`,
  `companies.emails.reverify` (scoped binding). React page `resources/js/pages/companies/show.tsx` (+
  `types/company-detail.ts`): header with score/status/type badges, website + Companies House links, outreach
  selector; company card with email sources + verification badges; founder 1/2 cards (role, appointed, source,
  LinkedIn, email + Re-verify, candidates tried); score breakdown per docs/06 line with item points; Laravel /
  offshore evidence and job ads; pipeline timeline with Retry / Re-run from step; editable notes. Company names in
  the leads table now link here.
- Tests: `tests/Feature/Companies/CompanyShowTest.php` (10): guest/404; full props (company, CH link, founders,
  candidates, breakdown lines, evidence, signals, events); unscored lead; notes save/clear/max; outreach contacted →
  replied keeps first contact, not interested suppresses, bad value refused; retry errored lead from a step, refuse
  qualified, `done` refused; re-verify queued only for the company's own address (404 otherwise); no job for a
  suppressed lead; job makes one MV call, founder email re-picked, score drops; only good address invalid → rejected
  `no_valid_email`. 514 passing; Pint, Larastan, vp check, tsc, vp build clean.
  Manual browser check with the local demo seed: open from the leads table, breadcrumbs, all cards, notes save toast;
  no console errors. Demo note reset afterwards.
- Decisions: D91–D92.
- Notes / follow-ups: `app/Queries/CompanyIndexQuery.php` from P2.2 had Pint issues (missed locally) — fixed in this
  commit; check that CI for c367415 failed only on that. Demo seed leads have a score but no stored breakdown —
  the page says so.
