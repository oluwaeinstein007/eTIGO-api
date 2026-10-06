<?php

namespace Database\Factories;

use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleClass>
 */
class VehicleClassFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['Economy', 'Comfort', 'Premium', 'SUV', 'EV', 'Luxury', 'XL', 'Green'];
        $name = fake()->unique()->randomElement($types);

        return [
            'name' => strtolower($name),
            'display_name' => $name,
            'capacity' => fake()->numberBetween(2, 7),
            'icon' => null,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
