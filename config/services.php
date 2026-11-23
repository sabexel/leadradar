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

    // Netherlands registry (docs/04). Zoeken is free; each basisprofiel costs €0.02.
    'kvk' => [
        'key' => env('KVK_API_KEY'),
        'base_url' => env('KVK_BASE_URL', 'https://api.kvk.nl/api'),
        'timeout' => 15,
        'per_minute' => 60,
    ],

    // US registries via OpenCorporates (docs/04). Paid plans have small monthly call budgets.
    'opencorporates' => [
        'token' => env('OPENCORPORATES_API_TOKEN'),
        'base_url' => env('OPENCORPORATES_BASE_URL', 'https://api.opencorporates.com/v0.4'),
        'timeout' => 20,
        'per_minute' => 20,
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
        'max_pages' => 10, // safety stop per keyword per run (10 × 50 ads)
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
        'results_per_query' => 10, // API max is 20; one query costs one unit either way

        // Never a company's own website (docs/04). Subdomains are blocked too; *.gov.uk always is.
        'blocked_domains' => [
            // registries and company-data directories
            'company-information.service.gov.uk', 'endole.co.uk', 'companycheck.co.uk', 'opencorporates.com',
            'companiesintheuk.co.uk', 'bizdb.co.uk', 'companyhouse.co.uk', 'dnb.com', 'bloomberg.com',
            'crunchbase.com', 'zoominfo.com', 'rocketreach.co', 'apollo.io', 'lusha.com', 'cbinsights.com',
            'pitchbook.com', 'owler.com', 'craft.co', 'northdata.com', 'globaldatabase.com', 'thegazette.co.uk',
            'gbrbusiness.com', 'opengovuk.com', 'checkall.co.uk', 'ukbizdb.co.uk', 'reportingaccounts.com',
            'huntukvisasponsors.com', 'arbolus.com', 'freehire.me', 'kvk.nl', 'openkvk.nl', 'drimble.nl',
            'oozo.nl', 'companyinfo.nl', 'bizapedia.com', 'bbb.org', 'manta.com', 'buzzfile.com', 'dandb.com',
            // business listings and reviews
            'yell.com', 'thomsonlocal.com', 'freeindex.co.uk', 'scoot.co.uk', 'cylex-uk.co.uk', 'hotfrog.co.uk',
            'yelp.com', 'yelp.co.uk', 'trustpilot.com', 'checkatrade.com', 'bark.com',
            // agency directories
            'clutch.co', 'goodfirms.co', 'designrush.com', 'sortlist.com', 'sortlist.co.uk', 'themanifest.com',
            'agencyspotter.com', 'upcity.com', 'thedrum.com',
            // job boards and freelance marketplaces
            'reed.co.uk', 'indeed.com', 'indeed.co.uk', 'adzuna.co.uk', 'totaljobs.com', 'cwjobs.co.uk',
            'glassdoor.com', 'glassdoor.co.uk', 'monster.co.uk', 'cv-library.co.uk', 'jobsite.co.uk',
            'workable.com', 'otta.com', 'welcometothejungle.com', 'upwork.com', 'fiverr.com', 'peopleperhour.com',
            // social, code hosting, encyclopaedias, maps
            'linkedin.com', 'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'youtube.com', 'tiktok.com',
            'pinterest.com', 'github.com', 'gitlab.com', 'medium.com', 'wikipedia.org', 'google.com',
            'google.co.uk', 'bing.com', 'apple.com',
        ],
        // Extra exclusions for agency searches (D113): platforms, marketplaces, agency rankings and lists.
        'agency_blocked_domains' => [
            'wordpress.org', 'wordpress.com', 'wpengine.com', 'shopify.com', 'shopify.co.uk', 'wix.com',
            'squarespace.com', 'webflow.com', 'godaddy.com', 'hostinger.com', 'envato.com', 'themeforest.net',
            'codecanyon.net', 'g2.com', 'capterra.com', 'expertise.com', 'houzz.com', 'thumbtack.com', 'forbes.com',
            'hubspot.com', 'semrush.com', 'ahrefs.com', 'reddit.com', 'quora.com', 'w3techs.com', 'builtwith.com',
            'digitalagencynetwork.com', 'agencyvista.com', '50pros.com', 'topdevelopers.co', 'techreviewer.co',
            'selectedfirms.co', 'appfutura.com', 'itfirms.co', 'superbcompanies.com', 'designrush.com',
            'goodfirms.co', 'clutch.co', 'sortlist.com', 'sortlist.be', 'sortlist.nl', 'sortlist.de', 'sortlist.fr',
            'themanifest.com', 'upcity.com', 'agencyspotter.com', 'bark.com', 'yelp.ca', 'yelp.com.au',
            'yellowpages.com', 'yellowpages.ca', 'yellowpages.com.au', 'truelocal.com.au', 'hotfrog.com',
            'gelbeseiten.de', 'goldenpages.ie', 'detelefoongids.nl', 'trustpilot.com', 'productreview.com.au',
            'freelancer.com', 'freelancer.co.uk', 'toptal.com', 'guru.com', 'contra.com', 'dribbble.com',
            'behance.net', 'awwwards.com', 'cssdesignawards.com', 'wikipedia.org', 'youtube.com', 'linkedin.com',
            'agencies.shopify.com', 'experts.shopify.com', 'partners.shopify.com', 'woocommerce.com',
            'codeable.io', 'wpbeginner.com', 'wpexplorer.com', 'elementor.com', 'semji.com', 'medium.com',
            'businessofapps.com', 'digital.com', 'clutch.com', 'goodfirms.com', 'agencyanalytics.com',
        ],
    ],

    'crawler' => [
        'user_agent' => env('CRAWLER_USER_AGENT', 'LeadRadarBot/1.0 (+https://webwayout.co.uk/bot)'),
        'timeout' => 10,
        'max_body_bytes' => 2 * 1024 * 1024,
        'min_seconds_between_requests' => 2,
        'robots_cache_hours' => 24,
    ],

    // P4.1 (D108): the public partner directory; fetched with the crawler's User-Agent and robots rules.
    'laravel_partners' => [
        'url' => 'https://laravel.com/partners',
    ],

    // Website leads (D111): Google Places API (New) Text Search — never scrape Maps HTML.
    'google_places' => [
        'key' => env('GOOGLE_PLACES_API_KEY'),
        'base_url' => env('GOOGLE_PLACES_BASE_URL', 'https://places.googleapis.com'),
        'timeout' => 20,
        'per_minute' => 30,
    ],

];
