<?php

return [
    'monitoring_window_minutes' => (int) env('OFFLINE_MONITORING_WINDOW', 15),

    'analysis_delay_minutes' => (int) env('OFFLINE_ANALYSIS_DELAY', 20),

    'collocation_threshold_meters' => (int) env('OFFLINE_COLLOCATION_THRESHOLD', 200),

    'route_match_percentage' => (int) env('OFFLINE_ROUTE_MATCH_PCT', 60),

    'min_collocation_points' => (int) env('OFFLINE_MIN_COLLOCATION_POINTS', 3),

    'pickup_proximity_meters' => (int) env('OFFLINE_PICKUP_PROXIMITY', 300),

    'sanction_lookback_days' => (int) env('OFFLINE_SANCTION_LOOKBACK_DAYS', 30),

    'suspension_hours' => (int) env('OFFLINE_SUSPENSION_HOURS', 48),

    'location_sample_interval_seconds' => (int) env('OFFLINE_LOCATION_SAMPLE_INTERVAL', 30),
];
