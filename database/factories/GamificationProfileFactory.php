<?php

namespace Database\Factories;

use App\Enums\TierLevel;
use App\Models\GamificationProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GamificationProfile> */
class GamificationProfileFactory extends Factory
{
    protected $model = GamificationProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'total_carbon_score' => fake()->randomFloat(2, 0, 500),
            'total_ranking_points' => fake()->numberBetween(0, 5000),
            'current_tier' => TierLevel::Bronze->value,
        ];
    }

    public function silver(): static
    {
        return $this->state(fn () => [
            'current_tier' => TierLevel::Silver->value,
            'total_ranking_points' => fake()->numberBetween(500, 1999),
            'tier_upgraded_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    public function gold(): static
    {
        return $this->state(fn () => [
            'current_tier' => TierLevel::Gold->value,
            'total_ranking_points' => fake()->numberBetween(2000, 4999),
            'tier_upgraded_at' => now()->subDays(fake()->numberBetween(1, 60)),
        ]);
    }

    public function platinum(): static
    {
        return $this->state(fn () => [
            'current_tier' => TierLevel::Platinum->value,
            'total_ranking_points' => fake()->numberBetween(5000, 14999),
            'tier_upgraded_at' => now()->subDays(fake()->numberBetween(1, 90)),
        ]);
    }

    public function diamond(): static
    {
        return $this->state(fn () => [
            'current_tier' => TierLevel::Diamond->value,
            'total_ranking_points' => fake()->numberBetween(15000, 50000),
            'tier_upgraded_at' => now()->subDays(fake()->numberBetween(1, 120)),
        ]);
    }
}
