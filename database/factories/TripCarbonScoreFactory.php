<?php

namespace Database\Factories;

use App\Models\Ride;
use App\Models\TripCarbonScore;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TripCarbonScore> */
class TripCarbonScoreFactory extends Factory
{
    protected $model = TripCarbonScore::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $distanceKm = fake()->randomFloat(2, 1, 50);
        $baselineEmission = $distanceKm * 0.120;
        $vehicleEmission = $distanceKm * 0.065;
        $co2Saved = $baselineEmission - $vehicleEmission;
        $basePoints = max(5, (int) round($co2Saved * 10));

        return [
            'ride_id' => Ride::factory(),
            'user_id' => User::factory(),
            'distance_km' => $distanceKm,
            'baseline_emission' => $baselineEmission,
            'vehicle_emission' => $vehicleEmission,
            'co2_saved' => $co2Saved,
            'base_points' => $basePoints,
            'multiplier_applied' => 1.00,
            'multiplier_reason' => null,
            'final_points' => $basePoints,
            'created_at' => now(),
        ];
    }

    public function withMultiplier(float $multiplier, string $reason): static
    {
        return $this->state(function (array $attributes) use ($multiplier, $reason) {
            $finalPoints = (int) round($attributes['base_points'] * $multiplier);

            return [
                'multiplier_applied' => $multiplier,
                'multiplier_reason' => $reason,
                'final_points' => $finalPoints,
            ];
        });
    }
}
