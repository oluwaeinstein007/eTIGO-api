<?php

namespace Database\Factories;

use App\Models\CommissionConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommissionConfig> */
class CommissionConfigFactory extends Factory
{
    protected $model = CommissionConfig::class;

    public function definition(): array
    {
        return [
            'rate' => 0.2000,
            'driver_id' => null,
            'is_active' => true,
        ];
    }

    public function forDriver(string $driverId): static
    {
        return $this->state(fn () => [
            'driver_id' => $driverId,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
