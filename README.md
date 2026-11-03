# LeadRadar

A Laravel + Inertia (React) app that finds companies that need Laravel help, checks them against official
registries, verifies contact details, and scores each lead from 0 to 10 on a dashboard.

Built by [Webwayout Ltd](https://webwayout.co.uk) as an internal tool and an open example of a clean,
queue-driven Laravel architecture.

## How it works

1. **Signals:** UK job ads that mention Laravel (Reed, Adzuna APIs).
2. **Registry checks:** Companies House: active, correct legal form, not dormant, directors.
3. **Enrichment:** company website (Brave Search API), polite crawl of up to 6 pages (contact and careers
   emails, Laravel and offshore signals), founder LinkedIn URLs (via search API, never by scraping LinkedIn).
4. **Verification:** MillionVerifier.
5. **Scoring:** hard gates, then a transparent 0–10 score with per-criterion breakdown.

## Architecture in one rule

> **Services own every external call. Jobs exist only for outbound work: one call per job.
> Local logic runs synchronously.**

See [`docs/02-architecture.md`](docs/02-architecture.md).

## Stack

Laravel · Inertia · React · TypeScript · Tailwind · shadcn/ui · MySQL · Pest · GitHub Actions ·
runs on shared hosting via a cron-driven queue worker.

## Setup

```bash
git clone https://github.com/<you>/leadradar.git && cd leadradar
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_* and the API keys in .env
php artisan migrate --seed
composer run dev
```

API keys needed: Companies House, Reed, Adzuna, MillionVerifier, Brave Search.

## Responsible use

LeadRadar obeys `robots.txt`, rate-limits every domain and API, never scrapes LinkedIn, and only qualifies
UK companies that may lawfully receive B2B email under PECR (limited companies and LLPs). It stores the
source of every personal-data field and supports suppression/erasure. See [`docs/09-compliance.md`](docs/09-compliance.md).

## Docs

`CLAUDE.md` (build instructions for AI agents) · `docs/brain/` (project state and log) · `docs/01–10` (spec).
