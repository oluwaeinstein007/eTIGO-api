<?php

namespace Database\Factories;

use App\Enums\HoldStatus;
use App\Models\Account;
use App\Models\Hold;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Hold> */
class HoldFactory extends Factory
{
    protected $model = Hold::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'ride_id' => null,
            'amount' => fake()->numberBetween(50000, 500000),
            'status' => HoldStatus::Active,
            'expires_at' => now()->addHours(4),
            'created_at' => now(),
        ];
    }

    public function captured(): static
    {
        return $this->state(fn () => [
            'status' => HoldStatus::Captured,
            'captured_at' => now(),
        ]);
    }

    public function released(): static
    {
        return $this->state(fn () => [
            'status' => HoldStatus::Released,
            'released_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => HoldStatus::Active,
            'expires_at' => now()->subHour(),
        ]);
    }
}
