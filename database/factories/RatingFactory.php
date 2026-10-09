<?php

namespace Database\Factories;

use App\Models\Rating;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rating>
 */
class RatingFactory extends Factory
{
    protected $model = Rating::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ride = Ride::factory()->completed()->create();

        return [
            'ride_id' => $ride->id,
            'rated_by_user_id' => $ride->passenger_id,
            'rated_user_id' => $ride->driver_id,
            'score' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional(0.5)->sentence(),
            'created_at' => now(),
        ];
    }
}
