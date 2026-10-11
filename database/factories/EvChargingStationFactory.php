<?php

namespace Database\Factories;

use App\Enums\EvStationStatus;
use App\Models\City;
use App\Models\EvChargingStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvChargingStation> */
class EvChargingStationFactory extends Factory
{
    protected $model = EvChargingStation::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company() . ' Charging Hub',
            'city_id' => City::factory(),
            'lat' => fake()->latitude(6.0, 10.0),
            'lng' => fake()->longitude(3.0, 8.0),
            'address' => fake()->address(),
            'total_stalls' => fake()->numberBetween(4, 20),
            'status' => EvStationStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => EvStationStatus::Inactive,
        ]);
    }

    public function maintenance(): static
    {
        return $this->state(fn () => [
            'status' => EvStationStatus::Maintenance,
        ]);
    }
}
