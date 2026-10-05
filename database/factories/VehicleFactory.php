<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'make' => fake()->randomElement(['Toyota', 'Honda', 'Nissan', 'Hyundai', 'Kia']),
            'model' => fake()->randomElement(['Corolla', 'Civic', 'Sentra', 'Elantra', 'Rio']),
            'colour' => fake()->safeColorName(),
            'plate_number' => strtoupper(fake()->unique()->bothify('???-####')),
            'year' => fake()->numberBetween(2018, (int) date('Y')),
            'vehicle_class_id' => VehicleClass::factory(),
        ];
    }

    public function fleet(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_fleet' => true,
        ]);
    }
}
