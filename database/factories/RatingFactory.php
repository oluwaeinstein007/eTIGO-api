<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\Rating;
use App\Models\Ride;
use App\Models\User;
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
        return [
            'ride_id' => Ride::factory()->completed(),
            'rated_by_user_id' => User::factory()->state(['type' => UserType::Passenger]),
            'rated_user_id' => User::factory()->state(['type' => UserType::Driver]),
            'score' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional(0.5)->sentence(),
            'created_at' => now(),
        ];
    }
}
