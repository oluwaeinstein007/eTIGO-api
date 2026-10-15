<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demand-based surge
    |--------------------------------------------------------------------------
    |
    | Demand is the number of passengers who requested a ride in the city (and
    | class) within the window; supply is the drivers available right now
    | (online, approved, idle, recently located). A demand-based rule fires
    | when demand / supply reaches its min_demand_supply_ratio and at least
    | min_requests passengers are asking, so a single late-night request
    | never triggers surge. The figures are cached briefly per city and class.
    |
    */

    'demand_window_minutes' => (int) env('SURGE_DEMAND_WINDOW_MINUTES', 10),
    'demand_min_requests' => (int) env('SURGE_DEMAND_MIN_REQUESTS', 5),
    'demand_cache_seconds' => (int) env('SURGE_DEMAND_CACHE_SECONDS', 60),

];
