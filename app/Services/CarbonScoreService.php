<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\TripCarbonScore;
use App\Models\VehicleClass;

class CarbonScoreService
{
    /**
     * @return array{trip_carbon_score: TripCarbonScore, co2_saved_kg: float, base_points: int}
     */
    public function calculateForRide(Ride $ride, string $userId): array
    {
        $distanceKm = $this->resolveDistanceKm($ride);
        $baselineEmissionPerKm = config('gamification.baseline_emission_per_km', 120.0);
        $vehicleEmissionPerKm = $this->resolveVehicleEmission($ride->vehicleClass);

        $baselineEmission = $distanceKm * ($baselineEmissionPerKm / 1000);
        $vehicleEmission = $distanceKm * ($vehicleEmissionPerKm / 1000);
        $co2Saved = max(0, $baselineEmission - $vehicleEmission);

        $pointsPerKg = config('gamification.base_points_per_kg_saved', 10);
        $minimumPoints = config('gamification.minimum_points_per_trip', 5);
        $basePoints = max($minimumPoints, (int) round($co2Saved * $pointsPerKg));

        $tripScore = TripCarbonScore::create([
            'ride_id' => $ride->id,
            'user_id' => $userId,
            'distance_km' => $distanceKm,
            'baseline_emission' => round($baselineEmission, 4),
            'vehicle_emission' => round($vehicleEmission, 4),
            'co2_saved' => round($co2Saved, 4),
            'base_points' => $basePoints,
            'multiplier_applied' => 1.00,
            'final_points' => $basePoints,
            'created_at' => now(),
        ]);

        return [
            'trip_carbon_score' => $tripScore,
            'co2_saved_kg' => round($co2Saved, 4),
            'base_points' => $basePoints,
        ];
    }

    private function resolveDistanceKm(Ride $ride): float
    {
        $snapshot = $ride->pricing_snapshot;

        if (is_array($snapshot) && isset($snapshot['distance_km'])) {
            return (float) $snapshot['distance_km'];
        }

        return 0.0;
    }

    private function resolveVehicleEmission(VehicleClass $vehicleClass): float
    {
        $classKey = mb_strtolower($vehicleClass->name);
        $emissions = config('gamification.vehicle_class_emissions', []);

        return $emissions[$classKey] ?? config('gamification.default_vehicle_emission', 75.0);
    }
}
