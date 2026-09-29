<?php

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'type' => UserType::Passenger,
            'password' => static::$password ??= Hash::make('password'),
            'phone_verified_at' => now(),
            'is_active' => true,
        ];
    }

    public function passenger(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => UserType::Passenger,
        ]);
    }

    public function driver(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => UserType::Driver,
        ]);
    }

    public function admin(AdminRole $role = AdminRole::SuperAdmin): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => UserType::Admin,
            'admin_role' => $role,
            'email' => $attributes['email'] ?? fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
        ]);
    }

    public function safetyOperator(): static
    {
        return $this->admin(AdminRole::SafetyOperator);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
