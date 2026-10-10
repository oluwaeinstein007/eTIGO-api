<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\BankAccount;
use App\Models\Driver;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payout> */
class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'bank_account_id' => BankAccount::factory(),
            'amount' => fake()->numberBetween(100000, 5000000),
            'status' => PayoutStatus::Requested,
            'requested_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Paid,
            'approved_at' => now(),
            'paid_at' => now(),
            'gateway_transfer_id' => 'TRF_'.fake()->bothify('??????????'),
            'gateway_reference' => fake()->uuid(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PayoutStatus::Failed,
            'failure_reason' => 'Transfer failed',
        ]);
    }
}
