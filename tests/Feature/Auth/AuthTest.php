<?php

use App\Enums\UserType;
use App\Models\User;

it('registers a new passenger account', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '+2341234567890',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'type' => 'passenger',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'user' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'type' => 'passenger',
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'john@example.com',
        'type' => 'passenger',
    ]);
});

it('registers a new driver account with a driver record', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Jane',
        'last_name' => 'Driver',
        'phone' => '+2349876543210',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'type' => 'driver',
    ]);

    $response->assertCreated()
        ->assertJson([
            'user' => [
                'type' => 'driver',
            ],
        ]);

    $user = User::where('email', 'jane@example.com')->first();
    expect($user->type)->toBe(UserType::Driver);
    expect($user->driver)->not->toBeNull();
    expect($user->driver->status->value)->toBe('pending_review');
});

it('rejects registration with duplicate email', function () {
    User::factory()->passenger()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '+2341234567890',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('rejects registration with duplicate phone', function () {
    User::factory()->passenger()->create(['phone' => '+2341234567890']);

    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '+2341234567890',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('rejects registration with weak password', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '+2341234567890',
        'email' => 'john@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('logs in a passenger with valid credentials', function () {
    User::factory()->passenger()->create([
        'email' => 'passenger@test.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'passenger@test.com',
        'password' => 'password123',
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'user' => [
                'email' => 'passenger@test.com',
                'type' => 'passenger',
            ],
        ]);
});

it('logs in a driver with valid credentials', function () {
    $user = User::factory()->driver()->create([
        'email' => 'driver@test.com',
        'password' => 'password123',
    ]);
    $user->driver()->create([
        'status' => 'pending_review',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'driver@test.com',
        'password' => 'password123',
        'type' => 'driver',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'user', 'token']);
});

it('rejects login with wrong password', function () {
    User::factory()->passenger()->create([
        'email' => 'passenger@test.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'passenger@test.com',
        'password' => 'wrongpassword',
        'type' => 'passenger',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Invalid credentials.']);
});

it('rejects login with wrong user type', function () {
    User::factory()->passenger()->create([
        'email' => 'passenger@test.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'passenger@test.com',
        'password' => 'password123',
        'type' => 'driver',
    ]);

    $response->assertUnauthorized();
});

it('rejects login for deactivated user', function () {
    User::factory()->passenger()->inactive()->create([
        'email' => 'inactive@test.com',
        'password' => 'password123',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@test.com',
        'password' => 'password123',
        'type' => 'passenger',
    ]);

    $response->assertForbidden();
});

it('returns current user via me endpoint', function () {
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me');

    $response->assertOk()
        ->assertJsonStructure(['user' => ['id', 'first_name', 'last_name', 'email', 'type']]);
});

it('allows authenticated user to logout', function () {
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/auth/logout');

    $response->assertOk()
        ->assertJson(['message' => 'Logged out successfully.']);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});
