<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Vehicle;
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
            'make' => fake()->randomElement(['Toyota', 'Honda', 'Nissan', 'Hyundai', 'Kia', 'Tesla']),
            'model' => fake()->randomElement(['Corolla', 'Civic', 'Sentra', 'Elantra', 'Rio', 'Model 3']),
            'colour' => fake()->safeColorName(),
            'plate_number' => strtoupper(fake()->unique()->bothify('???-####')),
            'year' => fake()->numberBetween(2018, (int) date('Y')),
        ];
    }

    public function classApproved(string $vehicleClass = 'standard'): static
    {
        return $this->state(fn (array $attributes) => [
            'vehicle_class' => $vehicleClass,
            'vehicle_class_approved' => true,
        ]);
    }
}
