<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Carbon Emission Factors (g CO₂ per km)
    |--------------------------------------------------------------------------
    |
    | Baseline is the average private car emission used as the reference.
    | Vehicle class emissions are the per-passenger estimates for ride-share.
    | Source: EU/IPCC averages adjusted for ride-sharing occupancy.
    |
    */

    'baseline_emission_per_km' => (float) env('CARBON_BASELINE_EMISSION', 120.0),

    'vehicle_class_emissions' => [
        'economy' => 65.0,
        'comfort' => 80.0,
        'premium' => 95.0,
        'ev' => 0.0,
    ],

    'default_vehicle_emission' => 75.0,

    /*
    |--------------------------------------------------------------------------
    | Points Calculation
    |--------------------------------------------------------------------------
    |
    | base_points_per_kg_saved: points awarded per kg of CO₂ saved.
    | minimum_points_per_trip: floor points for any completed ride.
    |
    */

    'base_points_per_kg_saved' => (int) env('GAMIFICATION_POINTS_PER_KG', 10),

    'minimum_points_per_trip' => (int) env('GAMIFICATION_MIN_POINTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Off-Peak Hours
    |--------------------------------------------------------------------------
    */

    'off_peak_start' => env('GAMIFICATION_OFF_PEAK_START', '22:00'),

    'off_peak_end' => env('GAMIFICATION_OFF_PEAK_END', '06:00'),

];
