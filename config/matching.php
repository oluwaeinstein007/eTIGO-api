<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Search Radius (km)
    |--------------------------------------------------------------------------
    */
    'initial_radius_km' => (float) env('MATCHING_INITIAL_RADIUS_KM', 3.0),

    /*
    |--------------------------------------------------------------------------
    | Search Radius Tiers (km)
    |--------------------------------------------------------------------------
    |
    | 3 expanding search radius tiers for matching.
    |
    */
    'radius_tiers_km' => [
        (float) env('MATCHING_RADIUS_TIER_1_KM', 3.0),
        (float) env('MATCHING_RADIUS_TIER_2_KM', 7.0),
        (float) env('MATCHING_RADIUS_TIER_3_KM', 15.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Search Retries
    |--------------------------------------------------------------------------
    |
    | Number of automatic retries before transitioning to no_driver_found,
    | and delay in seconds between retry waves.
    |
    */
    'max_auto_retries' => (int) env('MATCHING_MAX_AUTO_RETRIES', 1),
    'auto_retry_delay_seconds' => (int) env('MATCHING_AUTO_RETRY_DELAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Driver Response Window (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a driver has to accept or reject before auto-rejection.
    |
    */
    'driver_response_timeout' => (int) env('MATCHING_DRIVER_RESPONSE_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Overall Matching Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum wall-clock time from ride creation to finding a match.
    | After this, the ride transitions to no_driver_found. Default: 5 minutes.
    |
    */
    'matching_timeout' => (int) env('MATCHING_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Max Candidates Per Radius
    |--------------------------------------------------------------------------
    |
    | Limit the GEOSEARCH result set per expansion step.
    |
    */
    'max_candidates_per_search' => (int) env('MATCHING_MAX_CANDIDATES', 20),

];
