<?php

namespace Database\Factories;

use App\Enums\DisputeCategory;
use App\Enums\DisputeQueue;
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
            'category' => fake()->randomElement(array_filter(DisputeCategory::cases(), fn ($c) => $c !== DisputeCategory::SafetyConcern)),
            'description' => fake()->paragraph(),
            'status' => DisputeStatus::Open,
            'queue' => DisputeQueue::Support,
            'escalation_tier' => 1,
            'sla_started_at' => now(),
            'first_response_due_at' => now()->addDay(),
            'resolution_due_at' => now()->addHours(72),
        ];
    }

    /**
     * A safety concern, with the Safety team.
     */
    public function safety(): static
    {
        return $this->state(fn () => [
            'category' => DisputeCategory::SafetyConcern,
            'queue' => DisputeQueue::Safety,
            'escalation_tier' => 2,
            'first_response_due_at' => now()->addHour(),
            'resolution_due_at' => now()->addDay(),
        ]);
    }

    public function tier(int $tier): static
    {
        return $this->state(fn () => ['escalation_tier' => $tier]);
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
            'decision_tier' => 1,
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
