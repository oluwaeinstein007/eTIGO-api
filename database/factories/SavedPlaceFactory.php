<?php

namespace Database\Factories;

use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedPlace> */
class SavedPlaceFactory extends Factory
{
    protected $model = SavedPlace::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'custom',
            'name' => fake()->company(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(6.3, 6.7),
            'longitude' => fake()->longitude(3.2, 3.6),
        ];
    }

    public function home(): static
    {
        return $this->state(fn () => [
            'type' => 'home',
            'name' => 'Home',
        ]);
    }

    public function work(): static
    {
        return $this->state(fn () => [
            'type' => 'work',
            'name' => 'Work',
        ]);
    }
}
