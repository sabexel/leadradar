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

    /*
    |--------------------------------------------------------------------------
    | Website leads — Google Places types (D111)
    |--------------------------------------------------------------------------
    |
    | Curated Table A types the owner can enable on Settings. Labels are shown
    | in the UI; values are sent as includedType to Places Text Search.
    |
    */

    'place_types' => [
        'plumber' => 'Plumber',
        'electrician' => 'Electrician',
        'roofing_contractor' => 'Roofer',
        'painter' => 'Painter',
        'locksmith' => 'Locksmith',
        'carpenter' => 'Carpenter',
        'hair_salon' => 'Hair salon',
        'beauty_salon' => 'Beauty salon',
        'barber_shop' => 'Barber',
        'nail_salon' => 'Nail salon',
        'restaurant' => 'Restaurant',
        'cafe' => 'Cafe',
        'bakery' => 'Bakery',
        'meal_takeaway' => 'Takeaway',
        'dentist' => 'Dentist',
        'physiotherapist' => 'Physiotherapist',
        'veterinary_care' => 'Vet',
        'gym' => 'Gym',
        'car_repair' => 'Car repair',
        'real_estate_agency' => 'Estate agent',
        'moving_company' => 'Removals',
        'storage' => 'Storage',
        'laundry' => 'Laundry',
        'florist' => 'Florist',
        'funeral_home' => 'Funeral home',
    ],

];
