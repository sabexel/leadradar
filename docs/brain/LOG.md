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
