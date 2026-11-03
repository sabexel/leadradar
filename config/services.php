<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LeadRadar external services (docs/04-services.md)
    |--------------------------------------------------------------------------
    |
    | Keys live only in .env. Daily caps live in the settings table so they can
    | be changed from the dashboard; per-minute limits are set here.
    |
    */

    'companies_house' => [
        'key' => env('COMPANIES_HOUSE_API_KEY'),
        'base_url' => env('COMPANIES_HOUSE_BASE_URL', 'https://api.company-information.service.gov.uk'),
        'timeout' => 15,
        'per_minute' => 100,
    ],

    'reed' => [
        'key' => env('REED_API_KEY'),
        'base_url' => env('REED_BASE_URL', 'https://www.reed.co.uk/api/1.0'),
        'timeout' => 15,
        'per_minute' => 60,
        'max_pages' => 10, // safety stop per keyword per run (10 × 100 ads)
    ],

    'adzuna' => [
        'app_id' => env('ADZUNA_APP_ID'),
        'app_key' => env('ADZUNA_APP_KEY'),
        'base_url' => env('ADZUNA_BASE_URL', 'https://api.adzuna.com/v1/api'),
        'country' => 'gb',
        'timeout' => 15,
        'per_minute' => 25,
    ],

    'millionverifier' => [
        'key' => env('MILLIONVERIFIER_API_KEY'),
        'base_url' => env('MILLIONVERIFIER_BASE_URL', 'https://api.millionverifier.com/api/v3'),
        'timeout' => 20,
        'verify_timeout' => 10,
        'per_minute' => 60,
        'reverify_after_days' => 90,
    ],

    'brave' => [
        'key' => env('BRAVE_SEARCH_API_KEY'),
        'base_url' => env('BRAVE_SEARCH_BASE_URL', 'https://api.search.brave.com/res/v1'),
        'timeout' => 15,
        'per_minute' => 60,
    ],

    'crawler' => [
        'user_agent' => env('CRAWLER_USER_AGENT', 'LeadRadarBot/1.0 (+https://webwayout.co.uk/bot)'),
        'timeout' => 10,
        'max_body_bytes' => 2 * 1024 * 1024,
        'min_seconds_between_requests' => 2,
        'robots_cache_hours' => 24,
    ],

];
