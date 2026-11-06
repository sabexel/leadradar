<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public registration
    |--------------------------------------------------------------------------
    |
    | LeadRadar is single-user. Turn this on only to create the first account,
    | then turn it off again. When false, the registration routes return 404.
    |
    */

    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Business day
    |--------------------------------------------------------------------------
    |
    | Daily caps and targets reset at midnight in this timezone. Jobs that hit a
    | daily cap are released until `caps_resume_at` the next business day.
    |
    */

    'timezone' => 'Europe/London',

    'caps_resume_at' => '06:00',

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | The daily failed-jobs summary goes here. When empty it goes to the
    | (single) user account's email address.
    |
    */

    'alert_email' => env('LEADRADAR_ALERT_EMAIL'),

];
