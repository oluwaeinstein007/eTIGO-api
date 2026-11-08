<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upfront Fares
    |--------------------------------------------------------------------------
    |
    | The passenger pays the fare they were quoted (plus any waiting charge).
    | The quote already allows for expected traffic, so the time charge is
    | only recalculated when the trip runs significantly longer: by more than
    | trip_time_tolerance_pct of the quoted travel time, and by at least
    | trip_time_tolerance_min_minutes. A trip that ends sooner keeps the quote.
    |
    | When a trip is recalculated, the billed time is capped at
    | max_billed_time_multiplier × the quoted time, so a driver who forgets to
    | end a trip cannot run up the fare. Capped rides are flagged for review.
    |
    */

    'trip_time_tolerance_pct' => (float) env('PRICING_TRIP_TIME_TOLERANCE_PCT', 25),

    'trip_time_tolerance_min_minutes' => (float) env('PRICING_TRIP_TIME_TOLERANCE_MIN_MINUTES', 10),

    'max_billed_time_multiplier' => (float) env('PRICING_MAX_BILLED_TIME_MULTIPLIER', 3),

    /*
    |--------------------------------------------------------------------------
    | Routing Fallback
    |--------------------------------------------------------------------------
    |
    | When every routing provider (Google, Mapbox) is unavailable, distance
    | falls back to a straight-line (Haversine) estimate. That estimate is
    | not trustworthy at long range, where road detours, bridges and one-way
    | networks diverge most from a straight line, so a booking is refused
    | past max_approximate_distance_km rather than committing a passenger
    | and driver to a fare that may be badly wrong. Surge is never applied
    | on top of an approximate distance: compounding two uncertain numbers
    | hurts drivers and passengers in opposite, unpredictable directions.
    |
    */

    'max_approximate_distance_km' => (float) env('PRICING_MAX_APPROXIMATE_DISTANCE_KM', 40),

];
