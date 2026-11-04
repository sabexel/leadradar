# 08 — Deployment on Hostinger

## Which Hostinger plan?

| Plan | Works? | How the queue runs |
|---|---|---|
| **Business / Cloud web hosting** (shared, SSH + cron) | ✅ Default target | Cron runs `schedule:run` every minute; the scheduler starts a short-lived worker |
| **VPS** | ✅ Best | Supervisor keeps `queue:work` running permanently |
| Single/Premium without SSH | ❌ | No SSH/cron control — upgrade |

**Confirm the plan before P3.1 and record it in `BRAIN.md`.**

## Shared/Cloud hosting setup

1. hPanel → PHP version **8.4+** (BRAIN D30); enable extensions: `pdo_mysql`, `mbstring`, `intl`, `curl`, `dom`, `fileinfo`, `zip`.
2. hPanel → Databases → create MySQL DB + user. From the app use host `127.0.0.1` (or `localhost`), port 3306.
3. Point the domain/subdomain (e.g. `leads.webwayout.co.uk`) document root at `…/leadradar/public`.
4. SSH in, clone the repo **outside** `public_html` if the panel allows, `composer install --no-dev --optimize-autoloader`.
5. Create `.env` on the server (never in git): `APP_ENV=production`, `APP_DEBUG=false`, DB creds, API keys,
   `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`.
6. `php artisan key:generate && php artisan migrate --force && php artisan db:seed --class=SettingsSeeder --force`
7. `php artisan config:cache route:cache view:cache event:cache`
8. **Frontend assets:** shared hosting usually has no Node — build in CI (below) and upload `public/build`.

### Cron (hPanel → Advanced → Cron Jobs)

```
* * * * * cd /home/<user>/leadradar && php artisan schedule:run >> /dev/null 2>&1
```
(If per-minute cron isn't allowed on the plan, use every 5 minutes and raise `--max-time` accordingly.)

### Scheduler (`routes/console.php`)

```php
Schedule::command('queue:work --queue=registry,verify,web,sources --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()->withoutOverlapping(2)->runInBackground();

Schedule::command('leadradar:discover')->dailyAt('06:10')->timezone('Europe/London');
Schedule::command('leadradar:close-runs')->everyFifteenMinutes();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily();               // prune old api_usages / pipeline_events
```

Why this works: every job is one HTTP call finishing in seconds (`docs/05`), so a 50-second worker started
each minute processes the queue steadily without needing a permanent process.

## VPS alternative

Supervisor program: `php artisan queue:work --queue=registry,verify,web,sources --sleep=3 --tries=3 --max-time=3600`
plus the same cron line for the scheduler (remove the `queue:work` schedule entry).

## CI/CD — GitHub Actions

`.github/workflows/ci.yml` (every push/PR): composer install → Pint check → Larastan → Pest → `npm ci && npm run build`.

`.github/workflows/deploy.yml` (on push to `main`, after CI passes):
1. Build assets (`npm ci && npm run build`), `composer install --no-dev`.
2. `rsync` over SSH to Hostinger (exclude `.env`, `storage/`, `node_modules/`, `.git/`).
3. SSH: `php artisan migrate --force && php artisan optimize && php artisan queue:restart`.

Secrets in GitHub → Settings → Secrets: `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`, `HOSTINGER_SSH_KEY`,
`HOSTINGER_PATH`. Never API keys — those live only in the server `.env`.

## Monitoring

- Failed jobs → daily summary email to the owner (simple scheduled command).
- Dashboard "Errors" card + Runs page.
- `APP_DEBUG=false` in production, logs in `storage/logs` with daily rotation.

## Local development

`composer run dev` (starter kit: server + queue listener + Vite). Use a local MySQL. Seed with **fake** data only
(`CompanyFactory`, `PersonFactory`) — never real prospects.
