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
    | Radius Expansion
    |--------------------------------------------------------------------------
    |
    | After exhausting candidates in the current radius, expand by this step
    | up to max_radius_km. Each expansion triggers a new GEOSEARCH.
    |
    */
    'radius_step_km' => (float) env('MATCHING_RADIUS_STEP_KM', 2.0),
    'max_radius_km' => (float) env('MATCHING_MAX_RADIUS_KM', 15.0),

    /*
    |--------------------------------------------------------------------------
    | Driver Response Window (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a driver has to accept or reject before auto-rejection.
    |
    */
    'driver_response_timeout' => (int) env('MATCHING_DRIVER_RESPONSE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Overall Matching Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum wall-clock time from ride creation to finding a match.
    | After this, the ride transitions to no_driver_found.
    |
    */
    'matching_timeout' => (int) env('MATCHING_TIMEOUT', 180),

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
