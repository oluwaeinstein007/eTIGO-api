<?php

namespace Database\Factories;

use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\FleetAgreement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyRemittance>
 */
class DailyRemittanceFactory extends Factory
{
    protected $model = DailyRemittance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agreement_id' => FleetAgreement::factory(),
            'driver_id' => Driver::factory(),
            'date' => now()->toDateString(),
            'target_amount' => 40000.00,
            'remitted_amount' => 0,
            'shortfall_amount' => 40000.00,
            'ride_count' => 0,
            'total_fares' => 0,
            'driver_earnings' => 0,
            'settled' => false,
        ];
    }

    public function met(float $targetAmount = 40000.00): static
    {
        $totalFares = $targetAmount + fake()->numberBetween(5000, 20000);

        return $this->state(fn (array $attributes) => [
            'remitted_amount' => $targetAmount,
            'shortfall_amount' => 0,
            'target_met_at' => now(),
            'ride_count' => fake()->numberBetween(8, 20),
            'total_fares' => $totalFares,
            'driver_earnings' => $totalFares - $targetAmount,
        ]);
    }

    public function settled(): static
    {
        return $this->state(fn (array $attributes) => [
            'settled' => true,
        ]);
    }
}
