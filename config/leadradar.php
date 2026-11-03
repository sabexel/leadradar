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

];
