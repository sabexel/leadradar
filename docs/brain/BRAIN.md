# 🧠 BRAIN — LeadRadar project state

> Claude Code: read this first, every session. Update it after every completed task.
> This file holds the *current* truth. History goes in `LOG.md`.

---

## Current status

- **Phase:** P1 — in progress
- **Last completed task:** P1.3 Ingest (recruiter filter, dedupe, company creation)
- **Working on:** P1.4
- **Blocked by:** — (building with faked APIs; owner is registering API keys)

## Next step

1. **P1.4** — `CompaniesHouseService` (`searchCompanies`, `getProfile`, `getOfficers`) + DTOs + `SearchCompaniesHouseJob`, `FetchCompanyProfileJob`, `FetchCompanyOfficersJob` + `ApplyCompaniesHouseResultAction`; wire the three registry steps into `AdvanceLeadPipelineAction` (the suppression gate there then also catches suppressed company numbers). Matching via `CompanyMatcher::similarity() ≥ 0.85` + active. Don't store DOB. Confirm CH API docs first.
2. Owner: put API keys in `.env`; then verify Reed JSON keys (D23) and Adzuna limits (D31) with one live call each.
3. Confirm the Hostinger plan offers PHP 8.4+ (D30).

---

## Build checklist

Copied from `docs/10-build-plan.md`. Tick `[x]` when the acceptance criteria are met.

### P0 — Foundation
- [x] P0.1 Create Laravel app (React starter kit), MySQL, Pest, Pint, Larastan
- [x] P0.2 Single-user auth (disable public registration after first user)
- [x] P0.3 `.env.example`, `config/services.php` entries, README skeleton
- [x] P0.4 Migrations + models + enums for all tables in `docs/03`
- [x] P0.5 `ApiUsage` logging + per-service daily caps (settings table)
- [x] P0.6 GitHub Actions: tests + Pint + Larastan on every push

### P1 — Core pipeline (UK, job-ad signal)
- [x] P1.1 `ReedService` + `FetchReedJobsJob` + `FetchReedJobDetailJob`
- [x] P1.2 `AdzunaService` + `FetchAdzunaJobsJob`
- [x] P1.3 `IngestJobAdAction` (dedupe, recruiter filter, create/attach Company)
- [ ] P1.4 `CompaniesHouseService` (search, profile, officers) + jobs
- [ ] P1.5 `CompanyClassifier` (agency / direct client / recruiter / offshore) from SIC + text
- [ ] P1.6 `BraveSearchService::findCompanyWebsite()` + job
- [ ] P1.7 `WebsiteCrawlerService` (pages, emails, Laravel signals, offshore signals) + job
- [ ] P1.8 `EmailPatternService` (local) — candidate founder emails
- [ ] P1.9 `MillionVerifierService` + `VerifyEmailJob` (stop at first `ok`, honour credit cap)
- [ ] P1.10 `BraveSearchService::findLinkedInProfile()` + job
- [ ] P1.11 `LeadScoringService` + gates (sync) → status `qualified` / `rejected`
- [ ] P1.12 `AdvanceLeadPipelineAction` state machine wiring all steps
- [ ] P1.13 Scheduler: daily discovery run, stops at daily qualified target
- [ ] P1.14 Suppression list + CSV import of already-contacted companies

### P2 — Dashboard
- [ ] P2.1 Dashboard page: counters, charts, last runs
- [ ] P2.2 Companies index: filters, sort, score badge, bulk actions
- [ ] P2.3 Company detail: all fields, founders, emails + verification, score breakdown, timeline
- [ ] P2.4 Mark contacted / replied / not interested (outreach status)
- [ ] P2.5 CSV export in the outreach-sheet column order
- [ ] P2.6 Runs & API usage page; Settings page (caps, target, key status)
- [ ] P2.7 Manual import: paste domains or upload CSV (e.g. Apollo export) → enrich

### P3 — Deploy
- [ ] P3.1 Hostinger deploy (GitHub Actions → SSH/rsync), cron, queue via scheduler
- [ ] P3.2 Production smoke test + monitoring (failed jobs alert by email)

### P4 — Extensions (only after P3 is live and producing leads)
- [ ] P4.1 Laravel Partners directory source
- [ ] P4.2 Netherlands (KVK) / US (OpenCorporates) registry support
- [ ] P4.3 Google Sheets push
- [ ] P4.4 Opening-line generator for outreach

---

## Decisions

| # | Date | Decision | Reason |
|---|---|---|---|
| D1 | 2026-11-03 | Laravel + Inertia React (official starter kit), MySQL | Owner's stack; doubles as public portfolio repo |
| D2 | 2026-11-03 | Host on Hostinger; queue = `database` driver run by the scheduler every minute | No supervisor/Redis on shared hosting |
| D3 | 2026-11-03 | Jobs only for outbound calls; one external call per job | Short jobs survive the cron-driven worker; clean retries |
| D4 | 2026-11-03 | No LinkedIn scraping; LinkedIn URLs via Brave Search API | LinkedIn ToS; protects owner's account |
| D5 | 2026-11-03 | Primary signal = UK job ads mentioning Laravel (Reed, Adzuna) | Highest-intent signal: budget + confirmed gap |
| D6 | 2026-11-03 | Daily target = 50 qualified leads, not raw volume | Finite market; owner sends 5–15/day |
| D7 | 2026-11-03 | UK leads must be an active Ltd/LLP on Companies House | PECR: sole traders/partnerships need consent |
| D8 | 2026-11-03 | Scaffolded from `laravel/react-starter-kit:dev-main` (Laravel 13, Inertia 3, Fortify, Wayfinder) | The tagged Packagist release (v1.0.1) is still Laravel 12; `laravel new` uses `main` too |
| D9 | 2026-11-03 | Local MySQL 8 runs in `docker-compose.yml` as `leadradar-mysql` on host port **3317** | 3306/3307 already used by other local containers; Hostinger still uses 3306 |
| D10 | 2026-11-03 | Tests run against MySQL database `leadradar_testing` (created by the docker init script), not SQLite | Owner's PHP has no `pdo_sqlite`; also gives prod parity for JSON/enum/index behaviour |
| D11 | 2026-11-03 | Registration stays a Fortify feature; `EnsureRegistrationEnabled` middleware 404s `register`/`register.store` unless `config('leadradar.registration_enabled')` | Routes stay registered so Wayfinder TS imports never break; flag can be flipped at runtime and in tests |
| D12 | 2026-11-03 | `vp fmt` ignores `*.md` and `docs/**` | Keeps the spec and brain files exactly as written |
| D13 | 2026-11-03 | `config/services.php` holds keys, base URLs, timeouts and per-minute limits (CH 100, Reed 60, Adzuna 25, MV 60, Brave 60); daily caps stay in `settings` | Per-minute limits are code-level safety; daily caps are owner-tunable from the dashboard |
| D14 | 2026-11-03 | `people` also has `source_url`; `people.position` is nullable (resigned / 3rd+ officers) | CLAUDE.md requires `source` + `source_url` for every personal-data field |
| D15 | 2026-11-03 | Extra enums beyond docs/03: `EmailOrigin`, `PersonRole`, `DataSource`, `PageType`, `PipelineOutcome`, `RunTrigger`, `ScrapeRunStatus`, `ExternalService`, `SuppressionType`, `SuppressionReason`. Columns are `string` + enum cast (no MySQL ENUM) | “PHP enums for every status/type field”; string columns avoid ALTERs when a case is added |
| D16 | 2026-11-03 | `crawled_pages.url` is `varchar(700)` | Unique `(company_id, url)` must fit MySQL utf8mb4 3072-byte key limit |
| D17 | 2026-11-03 | Retention: companies never contacted + idle 12 months (docs/09); `pipeline_events` 12 months; `api_usages` 180 days — all via `model:prune` | docs/08 says prune both tables but gave no window |
| D18 | 2026-11-03 | `Setting::DEFAULTS` is the single source of default settings; `Setting::get()` falls back to it; `SettingsSeeder` uses `firstOrCreate` (never overwrites) | Safe to re-run in production; code never breaks on a missing row |
| D19 | 2026-11-03 | `DatabaseSeeder` always seeds settings; demo user + fake companies only in `local`/`testing` | Never seed demo data into production |
| D20 | 2026-11-03 | Daily caps reset on the **Europe/London** date (`config/leadradar.timezone`); capped jobs resume next day 06:00 London (`caps_resume_at`). App timezone stays UTC | Matches the 06:10 London discovery run; avoids BST/GMT drift in counts |
| D21 | 2026-11-03 | `ApiUsageLimiter` checks three layers: per-minute (`config services.*.per_minute`, RateLimiter), rolling 5-min (`cap.*.per_5min`, counted from `api_usages`), daily (`cap.*.per_day`, sum of `cost_units`). Only per_day throws `DailyCapReachedException`; the others throw `RateLimitedException` | Short windows → release for seconds; daily caps → release until tomorrow (docs/05 §7) |
| D22 | 2026-11-03 | CI = single `.github/workflows/ci.yml` (replaces starter `tests.yml`): MySQL 8 service, PHP 8.3, Pint, Larastan, `npm run build`, `npm run check`, `tsc`, Pest; runs on every push + PR | Tests need MySQL (D10); build runs before type checks because Wayfinder generates route types during the build |
| D23 | 2026-11-03 | Reed JSON keys (`jobId`, `employerName`, `jobTitle`, `locationName`, `jobDescription`, `jobUrl`, `externalUrl`, `date`/`datePosted` as `dd/mm/yyyy`, `totalResults`) are mapped from Reed's known response shape; the official page lists field *labels* only | Verify against one live response once the key exists; fixtures mirror this shape |
| D24 | 2026-11-03 | `ReedService::search()` returns a `JobSearchPage` DTO (`ads` + `totalResults`) instead of a bare Collection | Pagination needs the total to stop on the last page |
| D25 | 2026-11-03 | Outbound jobs use `retryUntil()` = +3 days and `maxExceptions = 3` (with `backoff [30,120,600]`, `timeout 30`) instead of `tries = 3` — shared `InteractsWithServiceLimits` trait | Cap/rate-limit releases must not burn attempts; a capped job waits until 06:00 tomorrow without failing |
| D26 | 2026-11-03 | `ExternalApiService` base class: `ensureAvailable` → `Http` with `retry(2, 500)` on connection errors/5xx/429 only (2 attempts total) → `record` → 429 ⇒ `RateLimitedException`, other failures/malformed JSON ⇒ `ExternalServiceException`; missing key ⇒ exception before any call | One place for the docs/04 service contract; every later service extends it |
| D27 | 2026-11-03 | `FetchReedJobsJob` queues `FetchReedJobDetailJob` only for ads within `signal.max_age_days` that are not already Signals; next page only if the page is non-empty, has a fresh ad, `page × 100 < totalResults` and `page < services.reed.max_pages` (10). Daily target checked at the start of every page job | docs/05 stop rules; saves detail calls for known ads; Reed has no date filter so freshness is checked per page |
| D28 | 2026-11-03 | `IngestJobAdAction` currently only upserts the Signal and bumps `scrape_runs.signals_found`; P1.3 adds recruiter filter, company match/create, suppression, pipeline hand-over | Keeps P1.1 testable end-to-end without pulling P1.3 forward |
| D29 | 2026-11-03 | `Http::preventStrayRequests()` in the base `TestCase`; JSON fixtures via `jsonFixture()` (Pest already owns `fixture()`) | Enforces “no test may hit a real API” globally |
| D30 | 2026-11-04 | Minimum PHP is **8.4** (`composer.json` `php: ^8.4`, `config.platform.php = 8.4.1`, CI on 8.4); CLAUDE.md, README and docs/08 updated | The locked stack (Symfony 8.1, Pest 5, PHPUnit 13) needs PHP ≥ 8.4.1 — the first CI run on 8.3 failed at `composer install`. The platform pin stops a local PHP 8.5 from locking 8.5-only packages. Still satisfies the spec's "8.3+" for the server |
| D31 | 2026-11-04 | Adzuna: `GET /jobs/gb/search/{page}` with `app_id`/`app_key`, `what`, `results_per_page=50` (max), `max_days_old = signal.max_age_days`, `sort_by=date`; total from `count`. Self-limits 25/min and new setting `cap.adzuna.per_day = 200` | Adzuna's public docs don't state free-tier limits (only shown in the account); 25/min + 250/day are the commonly documented defaults, 200 leaves headroom. Owner to confirm in the Adzuna dashboard |
| D32 | 2026-11-04 | `FetchAdzunaJobsJob` ingests ads directly (no detail job) | Adzuna has no job-detail endpoint; the search result is all we get |
| D33 | 2026-11-04 | `ExternalApiService` redacts `app_id`/`app_key`/`key`/`token` query values from connection-error messages and does not chain the original exception | Adzuna (and later MillionVerifier) take keys in the URL; cURL errors include it and would land in logs and `failed_jobs` in a public-repo project |
| D34 | 2026-11-04 | Public repo: https://github.com/sabexel/leadradar (pushed 2026-11-04). Commits are authored with the owner's own email at the owner's choice | Owner confirmed |
| D35 | 2026-11-04 | Ingest dedupes on the **exact** `CompanyMatcher::normalise()` name (case, punctuation, accents, `&`→`and`, leading "the", trailing ltd/limited/llp/plc/uk… stripped), under a per-name cache lock + transaction. No fuzzy matching at ingest | A wrong fuzzy merge silently mixes two employers' signals; fuzzy similarity (Jaro-Winkler) is used for Companies House matching where candidates are logged for review |
| D36 | 2026-11-04 | Recruiter ads are skipped **entirely** (no Signal, no Company). `RecruiterDetector` = whole-word name patterns (spec list + executive search, headhunt, personnel, selection/careers/employment ltd, appointments) **or** ad text saying "on behalf of / my / our client is…" (singular "client" only) | docs/05 orders the skip before the Signal upsert; Adzuna ads often hide the agency behind a neutral name but the text gives it away; agencies say "our clients" (plural) |
| D37 | 2026-11-04 | `AdvanceLeadPipelineAction` is built incrementally: now = finished-status guard + suppression gate; each later step adds its dispatch. Ingest advances **only newly created** companies; a new signal for an existing company just attaches | Avoids pulling P1.12 forward; existing companies are already in/after the pipeline (re-scoring covers new evidence) |
| D38 | 2026-11-04 | `SuppressionList::matchFor()` matches domain, company number or any of the company's emails (all lowercased). The PipelineEvent records only the suppression `type` + `reason`, never the value | The value may be an email address (personal data) |
| D39 | 2026-11-04 | `Company::recordEvent(step, outcome, message, context)` is the one way to write a PipelineEvent | Consistent timeline entries from every action |

## Open questions

- Hostinger plan type (shared/cloud vs VPS) — confirm before P3.1. Default plan in `docs/08` assumes shared/cloud.
- Is Brave Search API's free tier enough for website + LinkedIn lookups at 50 leads/day? Check at P1.6.

## Environment / keys status

Record only **whether** a key is configured — never the value.

| Key | Local | Production |
|---|---|---|
| `COMPANIES_HOUSE_API_KEY` | ☐ | ☐ |
| `REED_API_KEY` | ☐ | ☐ |
| `ADZUNA_APP_ID` / `ADZUNA_APP_KEY` | ☐ | ☐ |
| `MILLIONVERIFIER_API_KEY` | ☐ | ☐ |
| `BRAVE_SEARCH_API_KEY` | ☐ | ☐ |

## Known issues

- `RecruiterDetector` flags any employer with the whole word "people" (spec keyword), e.g. a studio called "Good People Digital". Revisit if real data shows false positives.
