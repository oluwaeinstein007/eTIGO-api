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

    // Priority matching (Gold, Platinum, Diamond passengers): extra automatic
    // retry waves on top of max_auto_retries.
    'priority_extra_auto_retries' => (int) env('MATCHING_PRIORITY_EXTRA_RETRIES', 1),
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

    /*
    |--------------------------------------------------------------------------
    | Manual Assignment Window (hours)
    |--------------------------------------------------------------------------
    |
    | Rides that found no driver stay on the admin pending list (and in its
    | "needs attention" count) for this long after they were requested.
    |
    */
    'manual_assignment_window_hours' => (int) env('MATCHING_MANUAL_ASSIGNMENT_WINDOW_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Demo Driver Email Domain
    |--------------------------------------------------------------------------
    |
    | Drivers seeded by MonitoringDemoSeeder use this email domain. They are
    | for testing the admin dashboard and are never offered rides by
    | automatic matching; they can still be assigned manually.
    |
    */
    'demo_driver_email_domain' => env('MATCHING_DEMO_DRIVER_EMAIL_DOMAIN', 'ops-demo.etigo.com'),

    /*
    |--------------------------------------------------------------------------
    | Silent Drivers
    |--------------------------------------------------------------------------
    |
    | An online driver whose app has sent no location for the warning time
    | gets one push saying we cannot see them (matching cannot find them).
    | After the offline time a driver who is not on a trip is set offline and
    | told so. A driver on a trip is only ever warned.
    |
    */
    'silent_driver_warning_minutes' => (int) env('MATCHING_SILENT_DRIVER_WARNING_MINUTES', 5),

    'silent_driver_offline_minutes' => (int) env('MATCHING_SILENT_DRIVER_OFFLINE_MINUTES', 30),

];
