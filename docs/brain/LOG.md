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
