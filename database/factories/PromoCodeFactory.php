<?php

namespace Database\Factories;

use App\Enums\DiscountType;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(Str::random(8)),
            'description' => fake()->sentence(),
            'discount_type' => DiscountType::Percentage,
            'discount_value' => fake()->randomElement([5, 10, 15, 20, 25]),
            'max_discount_cap' => 2000.00,
            'total_redemption_limit' => 100,
            'per_user_limit' => 1,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
            'geo_fence' => null,
            'min_order_count' => null,
            'max_order_count' => null,
            'min_tier_level' => null,
            'peak_only' => false,
            'off_peak_only' => false,
            'city_id' => null,
            'vehicle_class_id' => null,
            'minimum_fare_amount' => null,
            'is_active' => true,
            'created_by_admin_id' => User::factory()->admin(),
        ];
    }

    public function flat(float $amount = 500): static
    {
        return $this->state(fn () => [
            'discount_type' => DiscountType::Flat,
            'discount_value' => $amount,
            'max_discount_cap' => null,
        ]);
    }

    public function percentage(int $pct = 20, ?float $cap = 2000): static
    {
        return $this->state(fn () => [
            'discount_type' => DiscountType::Percentage,
            'discount_value' => $pct,
            'max_discount_cap' => $cap,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function future(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->addDay(),
            'expires_at' => now()->addMonth(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function unlimited(): static
    {
        return $this->state(fn () => [
            'total_redemption_limit' => null,
            'per_user_limit' => 999,
        ]);
    }

    public function newUsersOnly(int $maxOrders = 3): static
    {
        return $this->state(fn () => [
            'max_order_count' => $maxOrders,
        ]);
    }

    public function tierRestricted(int $minTier = 3): static
    {
        return $this->state(fn () => [
            'min_tier_level' => $minTier,
        ]);
    }
}
