<?php

use App\Enums\UserType;
use App\Models\OtpCode;
use App\Models\User;

it('sends an OTP to a valid phone number', function () {
    $response = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '+2341234567890',
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Verification code sent successfully.']);

    $this->assertDatabaseHas('otp_codes', [
        'phone' => '+2341234567890',
        'purpose' => 'login',
    ]);
});

it('rejects OTP request with invalid phone format', function () {
    $response = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '1234',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('rejects OTP request with invalid user type', function () {
    $response = $this->postJson('/api/v1/auth/otp/request', [
        'phone' => '+2341234567890',
        'type' => 'admin',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('creates a new passenger account on first OTP verification', function () {
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => '123456',
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '123456',
        'type' => 'passenger',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'user', 'token', 'is_new_user'])
        ->assertJson([
            'is_new_user' => true,
            'user' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+2341234567890',
                'type' => 'passenger',
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'phone' => '+2341234567890',
        'type' => 'passenger',
    ]);
});

it('creates a new driver account with a driver record on first verification', function () {
    OtpCode::create([
        'phone' => '+2349876543210',
        'code' => '654321',
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2349876543210',
        'code' => '654321',
        'type' => 'driver',
        'first_name' => 'Jane',
        'last_name' => 'Driver',
    ]);

    $response->assertCreated()
        ->assertJson(['is_new_user' => true]);

    $user = User::where('phone', '+2349876543210')->first();
    expect($user->type)->toBe(UserType::Driver);
    expect($user->driver)->not->toBeNull();
    expect($user->driver->status->value)->toBe('pending_review');
});

it('logs in an existing user on subsequent OTP verification', function () {
    $user = User::factory()->passenger()->create([
        'phone' => '+2341234567890',
    ]);

    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => '123456',
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '123456',
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJson(['is_new_user' => false])
        ->assertJsonStructure(['token']);
});

it('rejects an invalid OTP code', function () {
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => '123456',
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '999999',
        'type' => 'passenger',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertUnprocessable()
        ->assertJson(['message' => 'Invalid verification code.']);
});

it('rejects an expired OTP code', function () {
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => '123456',
        'purpose' => 'login',
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '123456',
        'type' => 'passenger',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $response->assertUnprocessable()
        ->assertJson(['message' => 'No active OTP found. Please request a new code.']);
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
