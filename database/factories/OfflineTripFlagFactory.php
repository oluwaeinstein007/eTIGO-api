<?php

namespace Database\Factories;

use App\Enums\DisputeOutcome;
use App\Enums\SanctionTier;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfflineTripFlag> */
class OfflineTripFlagFactory extends Factory
{
    protected $model = OfflineTripFlag::class;

    public function definition(): array
    {
        return [
            'ride_id' => Ride::factory(),
            'driver_id' => User::factory()->driver(),
            'passenger_id' => User::factory()->passenger(),
            'detection_data' => [
                'collocation_points' => fake()->numberBetween(3, 10),
                'route_match_percentage' => fake()->numberBetween(60, 95),
                'average_distance_meters' => fake()->numberBetween(50, 200),
                'monitoring_duration_seconds' => fake()->numberBetween(300, 900),
            ],
            'sanction_tier' => SanctionTier::Warning,
            'sanction_action' => SanctionTier::Warning->action(),
            'flagged_at' => now(),
        ];
    }

    public function tier2(): static
    {
        return $this->state(fn () => [
            'sanction_tier' => SanctionTier::Suspension,
            'sanction_action' => SanctionTier::Suspension->action(),
        ]);
    }

    public function tier3(): static
    {
        return $this->state(fn () => [
            'sanction_tier' => SanctionTier::Deactivation,
            'sanction_action' => SanctionTier::Deactivation->action(),
        ]);
    }

    public function disputed(): static
    {
        return $this->state(fn () => [
            'is_disputed' => true,
            'dispute_notes' => fake()->paragraph(),
            'dispute_outcome' => DisputeOutcome::Pending,
        ]);
    }

    public function upheld(): static
    {
        return $this->disputed()->state(fn () => [
            'dispute_outcome' => DisputeOutcome::Upheld,
            'dispute_resolved_by_admin_id' => User::factory()->admin(),
            'resolved_at' => now(),
        ]);
    }

    public function overturned(): static
    {
        return $this->disputed()->state(fn () => [
            'dispute_outcome' => DisputeOutcome::Overturned,
            'dispute_resolved_by_admin_id' => User::factory()->admin(),
            'resolved_at' => now(),
        ]);
    }
}
