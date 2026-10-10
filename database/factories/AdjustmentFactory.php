<?php

namespace Database\Factories;

use App\Enums\AdjustmentStatus;
use App\Enums\AdjustmentType;
use App\Models\Account;
use App\Models\Adjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Adjustment> */
class AdjustmentFactory extends Factory
{
    protected $model = Adjustment::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'type' => AdjustmentType::Credit,
            'amount' => fake()->numberBetween(10000, 500000),
            'reason' => fake()->sentence(8),
            'status' => AdjustmentStatus::Pending,
            'created_by_admin_id' => User::factory(),
        ];
    }

    public function debit(): static
    {
        return $this->state(fn () => [
            'type' => AdjustmentType::Debit,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => AdjustmentStatus::Approved,
            'approved_by_admin_id' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => AdjustmentStatus::Rejected,
        ]);
    }
}
