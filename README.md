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

Requires PHP 8.3+, Composer, Node 22+ and Docker (for the local MySQL 8 container).

```bash
git clone https://github.com/<you>/leadradar.git && cd leadradar
docker compose up -d          # MySQL 8 on localhost:3317 (+ leadradar_testing DB for tests)
cp .env.example .env          # then fill in the API keys
composer setup                # composer/npm install, key:generate, migrate, build
php artisan db:seed
composer run dev
```

Create your account once: set `REGISTRATION_ENABLED=true`, register at `/register`, then set it back to `false`
(registration routes return 404 while it is off).

API keys needed: Companies House, Reed, Adzuna, MillionVerifier, Brave Search. They live only in `.env`.

### Quality checks

```bash
./vendor/bin/pest             # tests (all external APIs faked with Http::fake())
./vendor/bin/pint --test      # code style
./vendor/bin/phpstan analyse  # Larastan
npm run check                 # frontend format + lint
```

## Responsible use

LeadRadar obeys `robots.txt`, rate-limits every domain and API, never scrapes LinkedIn, and only qualifies
UK companies that may lawfully receive B2B email under PECR (limited companies and LLPs). It stores the
source of every personal-data field and supports suppression/erasure. See [`docs/09-compliance.md`](docs/09-compliance.md).

## Docs

`CLAUDE.md` (build instructions for AI agents) · `docs/brain/` (project state and log) · `docs/01–10` (spec).
