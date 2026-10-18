<?php

/*
|--------------------------------------------------------------------------
| Multi-City Support (Phase 2, OQ-22)
|--------------------------------------------------------------------------
|
| Launch rules for new cities. Defaults follow the OQ-22 recommendation
| (16 Oct 2026, not yet client-confirmed). Operations can override the
| runtime values with PUT /api/v1/admin/settings/cities; read them through
| AppSetting::cities(), not config(), so overrides apply.
|
*/

return [

    // Approved, KYC-verified drivers a city needs before its first launch.
    'launch_min_approved_drivers' => (int) env('CITY_LAUNCH_MIN_APPROVED_DRIVERS', 30),

    // Require at least one active charging station when the city has
    // approved drivers with plug-in vehicles.
    'require_ev_charging' => (bool) env('CITY_REQUIRE_EV_CHARGING', true),

    // What happens to upcoming scheduled bookings when a city is paused and
    // the admin does not choose: "honour" (they still go ahead) or "cancel"
    // (each passenger is told).
    'pause_scheduled_rides' => env('CITY_PAUSE_SCHEDULED_RIDES', 'honour'),

    /*
    | Time zones and currencies a city may use. Fleet business days, promo
    | peak hours, off-peak windows and weekly payouts still run on Lagos
    | time, and fares are in naira, so a city outside Nigeria needs those
    | rules moved to the city's own time zone before it is added here.
    */
    'supported_timezones' => ['Africa/Lagos'],

    'supported_currencies' => ['NGN'],

];
