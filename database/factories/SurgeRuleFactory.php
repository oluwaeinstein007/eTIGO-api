<?php

namespace Database\Factories;

use App\Enums\SurgeType;
use App\Models\City;
use App\Models\SurgeRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurgeRule>
 */
class SurgeRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'vehicle_class_id' => null,
            'name' => fake()->words(3, true).' surge',
            'type' => SurgeType::Manual,
            'multiplier' => fake()->randomFloat(2, 1.2, 2.5),
            'conditions' => [],
            'priority' => 0,
            'is_active' => true,
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'created_by_admin_id' => User::factory()->admin(),
        ];
    }

    public function timeBased(array $schedule = []): static
    {
        return $this->state(fn () => [
            'type' => SurgeType::TimeBased,
            'conditions' => $schedule ?: [
                'days_of_week' => [1, 2, 3, 4, 5],
                'start_time' => '07:00',
                'end_time' => '09:00',
            ],
        ]);
    }

    public function demandBased(float $ratio = 2.0): static
    {
        return $this->state(fn () => [
            'type' => SurgeType::DemandBased,
            'conditions' => [
                'min_demand_supply_ratio' => $ratio,
            ],
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'type' => SurgeType::Manual,
            'conditions' => [],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'effective_from' => now()->subWeek(),
            'effective_until' => now()->subDay(),
        ]);
    }

    public function future(): static
    {
        return $this->state(fn () => [
            'effective_from' => now()->addDay(),
        ]);
    }
}
