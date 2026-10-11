<?php

namespace Database\Factories;

use App\Enums\EvStallStatus;
use App\Models\EvChargingStation;
use App\Models\EvChargingStall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvChargingStall> */
class EvChargingStallFactory extends Factory
{
    protected $model = EvChargingStall::class;

    public function definition(): array
    {
        return [
            'station_id' => EvChargingStation::factory(),
            'stall_number' => fake()->numberBetween(1, 20),
            'status' => EvStallStatus::Available,
        ];
    }

    public function occupied(): static
    {
        return $this->state(fn () => [
            'status' => EvStallStatus::Occupied,
            'current_vehicle_driver_id' => User::factory()->driver(),
            'occupied_since' => now()->subMinutes(fake()->numberBetween(5, 60)),
            'estimated_departure_at' => now()->addMinutes(fake()->numberBetween(5, 30)),
        ]);
    }

    public function reserved(): static
    {
        return $this->state(fn () => [
            'status' => EvStallStatus::Reserved,
        ]);
    }

    public function outOfService(): static
    {
        return $this->state(fn () => [
            'status' => EvStallStatus::OutOfService,
        ]);
    }

    public function departingSoon(): static
    {
        return $this->state(fn () => [
            'status' => EvStallStatus::Occupied,
            'current_vehicle_driver_id' => User::factory()->driver(),
            'occupied_since' => now()->subMinutes(45),
            'estimated_departure_at' => now()->addMinutes(10),
        ]);
    }
}
