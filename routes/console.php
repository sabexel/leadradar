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

Schedule::command('leadradar:discover')->dailyAt('06:10')->timezone('Europe/London');
Schedule::command('leadradar:close-runs')->everyFifteenMinutes();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('model:prune')->daily(); // companies (12 months idle), pipeline_events, api_usages
