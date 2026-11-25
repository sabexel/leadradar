<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| LeadRadar schedule (docs/08)
|--------------------------------------------------------------------------
|
| Hostinger runs `php artisan schedule:run` every minute from cron. The worker is short-lived: every job is
| one HTTP call (docs/05), so a 50-second worker each minute keeps the queues moving without supervisor.
| Queue order matters: finish enriching existing companies before pulling more signals.
|
*/

Schedule::command('queue:work --queue=registry,verify,web,sources --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// Second short-lived worker that takes searches and crawls first, so new leads don't wait behind
// registry and verification work (D125). Each job is one call, so two workers never double-spend.
Schedule::command('queue:work --queue=sources,web --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

Schedule::command('leadradar:discover')->dailyAt('06:10')->timezone('Europe/London');
Schedule::command('leadradar:close-runs')->everyFifteenMinutes();
Schedule::command('leadradar:cleanup')->hourly();
// Fill in contacts/owners for older leads, a little each hour so Brave stays within its cap (D136).
Schedule::command('leadradar:backfill-contacts --recrawl=20 --owners=10')->hourly();
Schedule::command('leadradar:report-failed-jobs')->dailyAt('07:00')->timezone('Europe/London');
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily(); // companies (12 months idle), pipeline_events, api_usages
