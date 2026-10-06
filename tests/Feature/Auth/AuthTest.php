<?php

use App\Contracts\SmsGateway;
use App\Enums\UserType;
use App\Models\City;
use App\Models\OtpCode;
use App\Models\User;

beforeEach(function () {
    $this->smsGateway = Mockery::mock(SmsGateway::class);
    $this->app->instance(SmsGateway::class, $this->smsGateway);
});

it('sends an OTP to a valid phone number', function () {
    $this->smsGateway->shouldReceive('send')->never();

    $response = $this->postJson('/api/v1/auth/otp/send', [
        'phone' => '+2341234567890',
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Verification code sent.'])
        ->assertJsonStructure(['expires_at']);

    $this->assertDatabaseHas('otp_codes', [
        'phone' => '+2341234567890',
        'purpose' => 'login',
    ]);
});

it('rejects OTP request with invalid phone format', function () {
    $response = $this->postJson('/api/v1/auth/otp/send', [
        'phone' => '1234567890',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('rate limits OTP requests within cooldown period', function () {
    $this->smsGateway->shouldReceive('send')->never();

    $this->postJson('/api/v1/auth/otp/send', ['phone' => '+2341234567890']);

    $response = $this->postJson('/api/v1/auth/otp/send', [
        'phone' => '+2341234567890',
    ]);

    $response->assertStatus(429)
        ->assertJson(['message' => 'Please wait before requesting another code.']);
});

it('logs in an existing user after OTP verification', function () {
    $user = User::factory()->passenger()->create([
        'phone' => '+2341234567890',
    ]);

    $code = '123456';
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', $code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => $code,
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'is_new_user' => false,
            'user' => [
                'phone' => '+2341234567890',
                'type' => 'passenger',
            ],
        ]);
});

it('returns is_new_user when phone is not registered', function () {
    $code = '123456';
    OtpCode::create([
        'phone' => '+2349999999999',
        'code' => hash('sha256', $code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2349999999999',
        'code' => $code,
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJson([
            'is_new_user' => true,
            'phone' => '+2349999999999',
        ]);
});

it('rejects OTP verification with wrong code', function () {
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => '000000',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJson(['message' => 'Invalid verification code.']);
});

it('rejects OTP verification for deactivated user', function () {
    User::factory()->passenger()->inactive()->create([
        'phone' => '+2341234567890',
    ]);

    $code = '123456';
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', $code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/otp/verify', [
        'phone' => '+2341234567890',
        'code' => $code,
        'type' => 'passenger',
    ]);

    $response->assertForbidden();
});

it('completes registration for a new passenger after OTP verification', function () {
    OtpCode::create([
        'phone' => '+2341234567890',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
        'verified_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/register/complete', [
        'phone' => '+2341234567890',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'type' => 'passenger',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'user', 'token'])
        ->assertJson([
            'user' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'type' => 'passenger',
            ],
        ]);

    $this->assertDatabaseHas('users', [
        'phone' => '+2341234567890',
        'type' => 'passenger',
    ]);
});

it('completes registration for a new driver with driver record', function () {
    OtpCode::create([
        'phone' => '+2349876543210',
        'code' => hash('sha256', '123456'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
        'verified_at' => now(),
    ]);

    $city = City::factory()->create();

    $response = $this->postJson('/api/v1/auth/register/complete', [
        'phone' => '+2349876543210',
        'first_name' => 'Jane',
        'last_name' => 'Driver',
        'type' => 'driver',
        'city_id' => $city->id,
    ]);

    $response->assertCreated()
        ->assertJson([
            'user' => ['type' => 'driver'],
        ]);

    $user = User::where('phone', '+2349876543210')->first();
    expect($user->type)->toBe(UserType::Driver);
    expect($user->driver)->not->toBeNull();
    expect($user->driver->status->value)->toBe('onboarding');
});

it('rejects registration without phone verification', function () {
    $response = $this->postJson('/api/v1/auth/register/complete', [
        'phone' => '+2341234567890',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'type' => 'passenger',
    ]);

    $response->assertForbidden()
        ->assertJson(['message' => 'Phone number not verified. Please verify your phone first.']);
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
