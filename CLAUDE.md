# CLAUDE.md — Instructions for Claude Code

You are building **LeadRadar**: a Laravel + Inertia (React) app that finds, checks and scores
B2B leads (agencies and companies that need Laravel help), and shows them on a dashboard.

## 1. Before you do anything in a session

1. Read `docs/brain/BRAIN.md` — it is the single source of truth for **what is done, what is next,
   and every decision made so far**.
2. Read the last ~20 entries of `docs/brain/LOG.md`.
3. Read only the spec docs relevant to the task you are about to do (see map below).
4. If `BRAIN.md` and a spec doc disagree, `BRAIN.md` wins (it records later decisions). Point out the conflict to the user.

## 2. After every completed task (non-negotiable)

1. Tick the task in `docs/brain/BRAIN.md` → "Build checklist".
2. Update "Current status" and "Next step" in `BRAIN.md`.
3. Append one entry to `docs/brain/LOG.md` (format is defined at the top of that file).
4. If you made or changed a design decision, add it to "Decisions" in `BRAIN.md` with the reason.
5. Commit with a message that matches the checklist item, e.g. `feat(companies-house): add officers lookup [P1.4]`.

Never end a session with code changes that are not reflected in the brain.

## 3. Spec map

| Doc | Read it when you are working on |
|---|---|
| `docs/01-overview.md` | Anything — the goal, target customer, constraints |
| `docs/02-architecture.md` | Folder structure, layering, the services-vs-jobs rule |
| `docs/03-database.md` | Migrations, models, enums |
| `docs/04-services.md` | Any external API (Companies House, Reed, Adzuna, MillionVerifier, Brave Search) or website crawling |
| `docs/05-pipeline-and-jobs.md` | Jobs, queues, pipeline steps, retries, rate limits, daily caps |
| `docs/06-scoring-and-checks.md` | Qualification gates and the 0–10 score |
| `docs/07-dashboard.md` | Inertia pages, filters, exports |
| `docs/08-deployment-hostinger.md` | Deploying, cron, queue worker on Hostinger |
| `docs/09-compliance.md` | Anything touching personal data, crawling rules, opt-outs |
| `docs/10-build-plan.md` | The phased task list with acceptance criteria |

## 4. Hard rules

- **Services own all external calls.** One service class per external system. Nothing outside
  `app/Services` may call `Http::` directly.
- **Jobs exist only for outbound work** (a network call to an API or website). A job is thin:
  it loads the model, calls ONE service method, stores the result, and hands over to the pipeline.
  Pure local logic (scoring, classification, dedupe, email-pattern generation) runs synchronously
  in Actions/Services — never in a job.
- **One external call per job.** Jobs must finish in seconds (the Hostinger queue worker is
  cron-driven and short-lived; see `docs/08`).
- **Never scrape LinkedIn.** LinkedIn URLs are found only through the search API (see `docs/04`).
- **Respect `robots.txt`** and per-domain rate limits when crawling. Identify the crawler with a User-Agent
  that names the project (no personal email in the UA).
- **Secrets only in `.env`.** This repo is public. Never commit API keys, real prospect data,
  exports, or database dumps. `.env.example` lists every key with an empty value.
- **Every external service must be fully faked in tests** with `Http::fake()` and sanitised fixtures.
  No test may hit a real API.
- **Store where each personal-data field came from** (`source` + `source_url`). See `docs/09`.
- Quality over volume: the daily target is **50 qualified leads**, not thousands of raw rows.

## 5. Conventions

- PHP 8.4+ (see BRAIN D30), latest stable Laravel, official **React starter kit** (Inertia + React + TypeScript + Tailwind + shadcn/ui).
- MySQL 8. Queue driver: `database`. Cache: `database` (no Redis on shared hosting).
- Pest for tests. Laravel Pint for formatting (`./vendor/bin/pint`). Larastan level 6+.
- PHP enums for every status/type field. DTOs (readonly classes) for every service response.
- Config for every external service lives in `config/services.php` under its own key.
- Use `declare(strict_types=1);` in new PHP files.
- Small PRs/commits per checklist item.
