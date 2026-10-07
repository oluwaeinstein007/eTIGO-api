<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ride>
 */
class RideFactory extends Factory
{
    protected $model = Ride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'vehicle_class_id' => VehicleClass::factory(),
            'passenger_id' => User::factory()->state(['type' => UserType::Passenger]),
            'pickup_lat' => fake()->latitude(6.4, 9.1),
            'pickup_lng' => fake()->longitude(3.3, 7.5),
            'pickup_address' => fake()->address(),
            'destination_lat' => fake()->latitude(6.4, 9.1),
            'destination_lng' => fake()->longitude(3.3, 7.5),
            'destination_address' => fake()->address(),
            'status' => RideStatus::Requested,
            'share_token' => Str::random(32),
            'fare_estimate_amount' => fake()->randomFloat(2, 1500, 25000),
            'fare_currency' => 'NGN',
            'pricing_snapshot' => [
                'pricing_config_id' => null,
                'version' => 1,
                'base_fare' => '600.00',
                'per_km_rate' => '250.00',
                'per_minute_rate' => '40.00',
                'minimum_fare' => '1500.00',
                'waiting_time_rate' => '50.00',
                'free_waiting_minutes' => 5,
                'effective_from' => now()->subDay()->toIso8601String(),
                'captured_at' => now()->toIso8601String(),
            ],
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Pending,
        ];
    }

    public function searching(): static
    {
        return $this->state(fn () => ['status' => RideStatus::Searching]);
    }

    public function matched(): static
    {
        return $this->state(fn () => [
            'status' => RideStatus::Matched,
            'driver_id' => User::factory()->state(['type' => UserType::Driver]),
            'matched_at' => now(),
        ]);
    }

    public function driverEnRoute(): static
    {
        return $this->state(fn () => [
            'status' => RideStatus::DriverEnRoute,
            'driver_id' => User::factory()->state(['type' => UserType::Driver]),
            'matched_at' => now()->subMinutes(2),
        ]);
    }

    public function driverArrived(): static
    {
        return $this->state(fn () => [
            'status' => RideStatus::DriverArrived,
            'driver_id' => User::factory()->state(['type' => UserType::Driver]),
            'matched_at' => now()->subMinutes(5),
            'pin_code' => '1234',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => RideStatus::InProgress,
            'driver_id' => User::factory()->state(['type' => UserType::Driver]),
            'matched_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(5),
            'pin_code' => '1234',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => RideStatus::Completed,
            'driver_id' => User::factory()->state(['type' => UserType::Driver]),
            'matched_at' => now()->subMinutes(30),
            'started_at' => now()->subMinutes(20),
            'completed_at' => now(),
            'final_fare_amount' => fake()->randomFloat(2, 1500, 25000),
            'pin_code' => '1234',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => RideStatus::Cancelled,
            'cancellation_reason' => 'changed_mind',
        ]);
    }

    public function noDriverFound(): static
    {
        return $this->state(fn () => ['status' => RideStatus::NoDriverFound]);
    }

    public function withCard(): static
    {
        return $this->state(fn () => ['payment_method' => PaymentMethod::Card]);
    }
}
