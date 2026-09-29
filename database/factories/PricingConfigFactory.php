<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricingConfig>
 */
class PricingConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'vehicle_class_id' => VehicleClass::factory(),
            'base_fare' => fake()->randomFloat(2, 200, 1000),
            'per_km_rate' => fake()->randomFloat(2, 50, 200),
            'per_minute_rate' => fake()->randomFloat(2, 10, 50),
            'minimum_fare' => fake()->randomFloat(2, 300, 800),
            'waiting_time_rate' => fake()->optional(0.5)->randomFloat(2, 5, 30),
            'version' => 1,
            'effective_from' => now()->subDay(),
            'created_by_admin_id' => User::factory()->admin(),
            'created_at' => now(),
        ];
    }

    public function future(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_from' => now()->addDays(fake()->numberBetween(1, 30)),
        ]);
    }

    public function forCityAndClass(City $city, VehicleClass $vehicleClass): static
    {
        return $this->state(fn (array $attributes) => [
            'city_id' => $city->id,
            'vehicle_class_id' => $vehicleClass->id,
        ]);
    }
}
