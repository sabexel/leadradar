# 🧠 BRAIN — LeadRadar project state

> Claude Code: read this first, every session. Update it after every completed task.
> This file holds the *current* truth. History goes in `LOG.md`.

---

## Current status

- **Phase:** P0 — not started
- **Last completed task:** —
- **Working on:** —
- **Blocked by:** —

## Next step

Start **P0.1** in `docs/10-build-plan.md` (create the Laravel app with the React starter kit).

---

## Build checklist

Copied from `docs/10-build-plan.md`. Tick `[x]` when the acceptance criteria are met.

### P0 — Foundation
- [ ] P0.1 Create Laravel app (React starter kit), MySQL, Pest, Pint, Larastan
- [ ] P0.2 Single-user auth (disable public registration after first user)
- [ ] P0.3 `.env.example`, `config/services.php` entries, README skeleton
- [ ] P0.4 Migrations + models + enums for all tables in `docs/03`
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
