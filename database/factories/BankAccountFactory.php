<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankAccount> */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'bank_code' => '058',
            'account_number' => fake()->numerify('##########'),
            'account_name' => fake()->name(),
            'is_verified' => true,
            'is_primary' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'is_verified' => false,
        ]);
    }
}
