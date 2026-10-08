<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'state' => fake()->state(),
            'region' => null,
            'boundary' => null,
            'area_sq_km' => null,
            'timezone' => 'Africa/Lagos',
            'currency_code' => 'NGN',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withBoundary(): static
    {
        return $this->state(fn (array $attributes) => [
            'boundary' => [
                'type' => 'Point',
                'coordinates' => [fake()->longitude(), fake()->latitude()],
                'radius_km' => 25,
            ],
        ]);
    }
}
