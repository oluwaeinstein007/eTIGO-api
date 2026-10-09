<?php

namespace Database\Factories;

use App\Enums\FleetAgreementStatus;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetAgreement>
 */
class FleetAgreementFactory extends Factory
{
    protected $model = FleetAgreement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'vehicle_id' => Vehicle::factory()->fleet(),
            'daily_remittance_target' => 40000.00,
            'total_vehicle_cost' => 5000000.00,
            'total_remitted' => 0,
            'agreement_start_date' => now()->toDateString(),
            'status' => FleetAgreementStatus::Active,
            'shortfall_streak_days' => 0,
            'created_by_admin_id' => User::factory(),
        ];
    }

    public function terminated(string $reason = 'Excessive shortfall'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FleetAgreementStatus::Terminated,
            'terminated_reason' => $reason,
            'terminated_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FleetAgreementStatus::Completed,
            'total_remitted' => $attributes['total_vehicle_cost'] ?? 5000000.00,
            'completed_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FleetAgreementStatus::Paused,
            'paused_at' => now(),
        ]);
    }
}
