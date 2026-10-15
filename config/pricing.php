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

];
