<?php

namespace Database\Factories;

use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoRedemption>
 */
class PromoRedemptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promo_code_id' => PromoCode::factory(),
            'user_id' => User::factory(),
            'ride_id' => Ride::factory(),
            'discount_amount' => fake()->randomFloat(2, 100, 2000),
            'redeemed_at' => now(),
        ];
    }
}
