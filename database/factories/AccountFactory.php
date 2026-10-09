<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'owner_type' => (new User)->getMorphClass(),
            'owner_id' => User::factory(),
            'type' => AccountType::PassengerWallet,
            'currency' => 'NGN',
            'status' => AccountStatus::Active,
            'balance' => 0,
            'balance_version' => 0,
        ];
    }

    public function passengerWallet(): static
    {
        return $this->state(fn () => [
            'type' => AccountType::PassengerWallet,
        ]);
    }

    public function driverEarningsPending(): static
    {
        return $this->state(fn () => [
            'type' => AccountType::DriverEarningsPending,
        ]);
    }

    public function driverEarningsAvailable(): static
    {
        return $this->state(fn () => [
            'type' => AccountType::DriverEarningsAvailable,
        ]);
    }

    public function system(AccountType $type): static
    {
        return $this->state(fn () => [
            'owner_type' => 'system',
            'owner_id' => '00000000-0000-0000-0000-000000000000',
            'type' => $type,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn () => [
            'status' => AccountStatus::Frozen,
        ]);
    }

    public function withBalance(int $kobo): static
    {
        return $this->state(fn () => [
            'balance' => $kobo,
        ]);
    }
}
