# 🧠 BRAIN — LeadRadar project state

> Claude Code: read this first, every session. Update it after every completed task.
> This file holds the *current* truth. History goes in `LOG.md`.

---

## Current status

- **Phase:** P0 — in progress
- **Last completed task:** P0.4 Schema (migrations, models, enums, factories)
- **Working on:** P0.5
- **Blocked by:** —

## Next step

**P0.5** — `ApiUsageLimiter` (daily caps from settings + per-minute `RateLimiter::for()`), `DailyCapReachedException`, `RateLimitedException`.

---

## Build checklist

Copied from `docs/10-build-plan.md`. Tick `[x]` when the acceptance criteria are met.

### P0 — Foundation
- [x] P0.1 Create Laravel app (React starter kit), MySQL, Pest, Pint, Larastan
- [x] P0.2 Single-user auth (disable public registration after first user)
- [x] P0.3 `.env.example`, `config/services.php` entries, README skeleton
- [x] P0.4 Migrations + models + enums for all tables in `docs/03`
- [ ] P0.5 `ApiUsage` logging + per-service daily caps (settings table)
- [ ] P0.6 GitHub Actions: tests + Pint + Larastan on every push

### P1 — Core pipeline (UK, job-ad signal)
- [ ] P1.1 `ReedService` + `FetchReedJobsJob` + `FetchReedJobDetailJob`
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
