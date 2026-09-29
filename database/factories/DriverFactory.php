<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->driver(),
            'status' => DriverStatus::PendingReview,
            'licence_number' => strtoupper(fake()->bothify('??###???')),
            'is_online' => false,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DriverStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Documents incomplete'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DriverStatus::Rejected,
            'rejection_reason' => $reason,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DriverStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }

    public function online(): static
    {
        return $this->approved()->state(fn (array $attributes) => [
            'is_online' => true,
        ]);
    }
}
