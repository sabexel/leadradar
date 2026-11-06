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

Requires PHP 8.4+, Composer, Node 22+ and Docker (for the local MySQL 8 container).

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

## Deploy (Hostinger shared hosting)

Full background in [`docs/08-deployment-hostinger.md`](docs/08-deployment-hostinger.md). Pushes to `main` deploy
automatically once CI passes (`.github/workflows/deploy.yml`); it can also be started by hand from the Actions tab.
The workflow builds the assets and the `--no-dev` vendor folder in CI, rsyncs them to the server (never `.env` or
`storage/`), then runs `migrate --force`, the settings seeder (adds new settings, never overwrites), `optimize` and
`queue:restart`, with the site in maintenance mode meanwhile.

### One-time server setup

1. hPanel → **Advanced → PHP Configuration**: PHP **8.4+**; extensions `pdo_mysql`, `mbstring`, `intl`, `curl`,
   `dom`, `fileinfo`, `zip`.
2. hPanel → **Databases**: create a MySQL database and user.
3. hPanel → **Advanced → SSH Access**: enable SSH, note host, port (usually `65002`) and user. Create a deploy key
   pair on your own machine (`ssh-keygen -t ed25519 -f leadradar_deploy -N ""`) and add the **public** key here.
4. Pick the app folder **outside** `public_html`, e.g. `/home/<user>/leadradar`, and create `.env` there by hand
   (template below). For `APP_KEY`, generate one locally with `php artisan key:generate --show` (never reuse your
   local key).
5. Point the (sub)domain's document root at `/home/<user>/leadradar/public`. If the panel won't allow a folder
   outside `public_html`, create the subdomain, delete its folder and symlink it:
   `ln -s /home/<user>/leadradar/public /home/<user>/domains/<domain>/public_html/<subdomain>` (path as hPanel shows).
6. GitHub → repo **Settings → Secrets and variables → Actions**: secrets `HOSTINGER_HOST`, `HOSTINGER_PORT`,
   `HOSTINGER_USER`, `HOSTINGER_SSH_KEY` (the **private** key) and `HOSTINGER_PATH` (`/home/<user>/leadradar`).
   Then add the repository **variable** `DEPLOY_ENABLED` = `true` (deploys are skipped until it exists). If `php`
   over SSH is not 8.4, also add a variable `HOSTINGER_PHP` with the full path (e.g. `/opt/alt/php84/usr/bin/php`).
7. Run the **deploy** workflow, then over SSH once: create your account with `REGISTRATION_ENABLED=true`, register,
   set it back to `false`, `php artisan config:cache`.
8. hPanel → **Advanced → Cron Jobs**, every minute (same PHP binary as above):
   `cd /home/<user>/leadradar && php artisan schedule:run >> /dev/null 2>&1`

Production `.env` (fill in the blanks; never commit it):

```dotenv
APP_NAME=LeadRadar
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://leads.example.co.uk

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# Hostinger mailbox SMTP (hPanel → Emails)
MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=LeadRadar
LEADRADAR_ALERT_EMAIL=

REGISTRATION_ENABLED=false
COMPANIES_HOUSE_API_KEY=
REED_API_KEY=
ADZUNA_APP_ID=
ADZUNA_APP_KEY=
MILLIONVERIFIER_API_KEY=
BRAVE_SEARCH_API_KEY=
CRAWLER_USER_AGENT="LeadRadarBot/1.0 (+https://webwayout.co.uk/bot)"
```

### Monitoring

`leadradar:report-failed-jobs` runs daily at 07:00 London and emails a summary of the jobs that failed in the last
24 hours to `LEADRADAR_ALERT_EMAIL` (or the user account's email). Nothing is sent on a clean day. Failed jobs can
be retried or removed on the Runs page; they are pruned after 7 days. Logs rotate daily in `storage/logs`.

### Production smoke test

Run after the first deploy, and after any deploy that touches the pipeline:

- [ ] `https://<domain>` loads the login page over HTTPS; `/register` returns 404.
- [ ] Log in; Dashboard, Leads, Runs, Imports and Settings all open without errors.
- [ ] Settings → **Test connection** passes for Companies House, Reed, Adzuna, MillionVerifier and Brave.
- [ ] `php artisan schedule:list` shows the worker, discover (06:10), close-runs, report-failed-jobs (07:00), pruning.
- [ ] Cron works: within two minutes of a deploy, `storage/logs` has no new errors and `jobs` drains
      (`php artisan queue:monitor database:registry,database:verify,database:web,database:sources`).
- [ ] Mail works: `php artisan tinker --execute="Mail::raw('LeadRadar mail test', fn (\$m) => \$m->to(config('leadradar.alert_email')))"`
      arrives.
- [ ] Imports → suppression import of the outreach sheet reports the expected number of rows.
- [ ] Runs → **Run discovery now**; within an hour the run shows signals and companies, the Runs page shows API usage
      and no failed jobs.
- [ ] The next morning the scheduled 06:10 run finishes on its own and qualified leads appear on the Dashboard
      (P3 acceptance).

## Responsible use

LeadRadar obeys `robots.txt`, rate-limits every domain and API, never scrapes LinkedIn, and only qualifies
UK companies that may lawfully receive B2B email under PECR (limited companies and LLPs). It stores the
source of every personal-data field and supports suppression/erasure. See [`docs/09-compliance.md`](docs/09-compliance.md).

## Docs

`CLAUDE.md` (build instructions for AI agents) · `docs/brain/` (project state and log) · `docs/01–10` (spec).
