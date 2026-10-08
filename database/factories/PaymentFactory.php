<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'ride_id' => Ride::factory(),
            'amount' => $this->faker->randomFloat(2, 500, 10000),
            'currency' => 'NGN',
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Pending,
            'tip_amount' => 0,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::PendingCollection,
        ]);
    }

    public function captured(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Captured,
            'gateway_transaction_id' => 'txn_'.$this->faker->uuid(),
        ]);
    }

    public function collected(): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Collected,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed,
            'failure_reason' => 'Insufficient funds',
        ]);
    }
}
