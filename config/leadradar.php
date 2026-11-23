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

    /*
    | Agency leads of any stack (D113): markets searched by the agency source. Each market lists cities and the
    | phrases searched there ("{phrase} {city}"). English phrases everywhere; local phrases where agencies
    | describe themselves in their own language.
    */
    'agency_phrases' => ['web design agency', 'wordpress agency', 'shopify agency', 'web development agency', 'woocommerce agency', 'digital agency'],

    'agency_markets' => [
        'GB' => ['cities' => ['London', 'Manchester', 'Birmingham', 'Leeds', 'Bristol', 'Glasgow', 'Edinburgh', 'Liverpool', 'Nottingham', 'Sheffield', 'Newcastle', 'Brighton', 'Cardiff', 'Belfast', 'Leicester', 'Southampton', 'Cambridge', 'Oxford', 'Reading', 'Exeter']],
        'US' => ['cities' => ['New York', 'Los Angeles', 'Chicago', 'Austin', 'Miami', 'Seattle', 'Denver', 'Boston', 'San Francisco', 'San Diego', 'Atlanta', 'Dallas', 'Houston', 'Phoenix', 'Portland', 'Minneapolis', 'Nashville', 'Philadelphia', 'Charlotte', 'Salt Lake City', 'Tampa', 'Raleigh', 'Columbus', 'Kansas City', 'Pittsburgh']],
        'CA' => ['cities' => ['Toronto', 'Vancouver', 'Montreal', 'Calgary', 'Ottawa', 'Edmonton', 'Winnipeg', 'Halifax', 'Victoria', 'Waterloo']],
        'AU' => ['cities' => ['Sydney', 'Melbourne', 'Brisbane', 'Perth', 'Adelaide', 'Gold Coast', 'Canberra', 'Newcastle NSW', 'Hobart']],
        'NZ' => ['cities' => ['Auckland', 'Wellington', 'Christchurch']],
        'IE' => ['cities' => ['Dublin', 'Cork', 'Galway', 'Limerick']],
        'NL' => ['cities' => ['Amsterdam', 'Rotterdam', 'Utrecht', 'Eindhoven', 'The Hague', 'Groningen'], 'phrases' => ['webbureau', 'wordpress bureau', 'shopify bureau', 'internetbureau']],
        'BE' => ['cities' => ['Brussels', 'Antwerp', 'Ghent', 'Leuven'], 'phrases' => ['webbureau', 'agence web']],
        'DE' => ['cities' => ['Berlin', 'Munich', 'Hamburg', 'Cologne', 'Frankfurt', 'Stuttgart', 'Düsseldorf', 'Leipzig'], 'phrases' => ['Webagentur', 'Internetagentur', 'WordPress Agentur', 'Shopify Agentur']],
        'AT' => ['cities' => ['Vienna', 'Graz', 'Linz'], 'phrases' => ['Webagentur', 'WordPress Agentur']],
        'DK' => ['cities' => ['Copenhagen', 'Aarhus'], 'phrases' => ['webbureau']],
        'SE' => ['cities' => ['Stockholm', 'Gothenburg', 'Malmö'], 'phrases' => ['webbyrå']],
        'NO' => ['cities' => ['Oslo', 'Bergen'], 'phrases' => ['webbyrå']],
        'FI' => ['cities' => ['Helsinki', 'Tampere']],
    ],

    /*
    | Email rules by country (docs/09, D115). "consent": cold B2B email needs prior consent (Germany UWG §7,
    | Austria TKG §174) — leads are kept but flagged, contact them by phone/LinkedIn. "published": only addresses
    | the company published itself (crawled) may be used, never guessed ones (Canada CASL implied consent, Australia
    | Spam Act conspicuous publication, and the stricter EU reading of ePrivacy). Other countries (GB corporate
    | subscribers, US CAN-SPAM) allow guessed founder addresses that MillionVerifier confirms.
    */
    'email_consent_countries' => ['DE', 'AT'],
    'email_guessing_countries' => ['GB', 'US'],

];
