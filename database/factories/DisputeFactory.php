<?php

namespace Database\Factories;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    protected $model = Dispute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ride_id' => Ride::factory()->completed(),
            'reported_by_user_id' => User::factory(),
            'category' => fake()->randomElement(DisputeCategory::cases()),
            'description' => fake()->paragraph(),
            'status' => DisputeStatus::Open,
        ];
    }

    public function underReview(): static
    {
        return $this->state(fn () => ['status' => DisputeStatus::UnderReview]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => DisputeStatus::Resolved,
            'resolution_notes' => fake()->paragraph(),
            'resolved_by_admin_id' => User::factory()->state(['type' => 'admin']),
            'resolved_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => [
            'status' => DisputeStatus::Dismissed,
            'resolution_notes' => fake()->paragraph(),
            'resolved_by_admin_id' => User::factory()->state(['type' => 'admin']),
            'resolved_at' => now(),
        ]);
    }
}
