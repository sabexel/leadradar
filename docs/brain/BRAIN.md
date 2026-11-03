# 🧠 BRAIN — LeadRadar project state

> Claude Code: read this first, every session. Update it after every completed task.
> This file holds the *current* truth. History goes in `LOG.md`.

---

## Current status

- **Phase:** P1 — in progress (P0.6 still awaits a first green run on GitHub)
- **Last completed task:** P1.1 Reed service + jobs
- **Working on:** P1.2
- **Blocked by:** — (building with faked APIs; live runs need the owner's API keys and the GitHub push)

## Next step

1. **P1.2** — `AdzunaService::search()` → `JobSearchPage` + `FetchAdzunaJobsJob`, reusing `ExternalApiService`, `InteractsWithServiceLimits` and `IngestJobAdAction`. Confirm Adzuna docs + free-tier limits first.
2. Owner (still open): create the GitHub repo + push so P0.6 can be ticked; add API keys to `.env`.
3. When a real `REED_API_KEY` exists, run one live search and compare the JSON keys with `tests/Fixtures/reed/*.json` (D23).

---

## Build checklist

Copied from `docs/10-build-plan.md`. Tick `[x]` when the acceptance criteria are met.

### P0 — Foundation
- [x] P0.1 Create Laravel app (React starter kit), MySQL, Pest, Pint, Larastan
- [x] P0.2 Single-user auth (disable public registration after first user)
- [x] P0.3 `.env.example`, `config/services.php` entries, README skeleton
- [x] P0.4 Migrations + models + enums for all tables in `docs/03`
- [x] P0.5 `ApiUsage` logging + per-service daily caps (settings table)
- [~] P0.6 GitHub Actions: tests + Pint + Larastan on every push (workflow written + passes locally; awaiting first green run on GitHub)

### P1 — Core pipeline (UK, job-ad signal)
- [x] P1.1 `ReedService` + `FetchReedJobsJob` + `FetchReedJobDetailJob`
- [ ] P1.2 `AdzunaService` + `FetchAdzunaJobsJob`
- [ ] P1.3 `IngestJobAdAction` (dedupe, recruiter filter, create/attach Company)
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

- —
