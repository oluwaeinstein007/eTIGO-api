<?php

namespace Database\Factories;

use App\Enums\EvReservationStatus;
use App\Models\EvChargingStation;
use App\Models\EvChargingStall;
use App\Models\EvReservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EvReservation> */
class EvReservationFactory extends Factory
{
    protected $model = EvReservation::class;

    public function definition(): array
    {
        return [
            'station_id' => EvChargingStation::factory(),
            'driver_id' => User::factory()->driver(),
            'status' => EvReservationStatus::Reserved,
            'fee_amount' => config('ev_charging.reservation_fee', 500.00),
            'fee_waived' => false,
            'reserved_at' => now(),
        ];
    }

    public function withStall(): static
    {
        return $this->state(fn () => [
            'stall_id' => EvChargingStall::factory(),
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => EvReservationStatus::Queued,
            'queue_position' => fake()->numberBetween(1, 10),
            'estimated_available_at' => now()->addMinutes(fake()->numberBetween(5, 30)),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => EvReservationStatus::Active,
            'stall_id' => EvChargingStall::factory(),
            'activated_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => EvReservationStatus::Completed,
            'stall_id' => EvChargingStall::factory(),
            'activated_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => EvReservationStatus::Expired,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => EvReservationStatus::Cancelled,
        ]);
    }

    public function feeWaived(): static
    {
        return $this->state(fn () => [
            'fee_waived' => true,
            'fee_amount' => 0,
        ]);
    }
}
