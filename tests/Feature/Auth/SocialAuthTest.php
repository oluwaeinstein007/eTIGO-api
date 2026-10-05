<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAuthService;

function mockSocialAuthService(array $returnData): void
{
    $mock = Mockery::mock(SocialAuthService::class);
    $mock->shouldReceive('verifyToken')->andReturn($returnData);
    app()->instance(SocialAuthService::class, $mock);
}

function mockSocialAuthServiceReturnsNull(): void
{
    $mock = Mockery::mock(SocialAuthService::class);
    $mock->shouldReceive('verifyToken')->andReturn(null);
    app()->instance(SocialAuthService::class, $mock);
}

it('creates a new passenger via Google social login', function () {
    mockSocialAuthService([
        'id' => '123456',
        'email' => 'social@example.com',
        'first_name' => 'Social',
        'last_name' => 'User',
    ]);

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'fake-google-token',
        'type' => 'passenger',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'user', 'token', 'is_new_user'])
        ->assertJson([
            'is_new_user' => true,
            'user' => [
                'first_name' => 'Social',
                'last_name' => 'User',
                'email' => 'social@example.com',
                'type' => 'passenger',
            ],
        ]);

    $this->assertDatabaseHas('social_accounts', [
        'provider' => 'google',
        'provider_id' => '123456',
    ]);
});

it('creates a new driver via social login with pending_review status', function () {
    mockSocialAuthService([
        'id' => '789',
        'email' => 'driver@social.com',
        'first_name' => 'Social',
        'last_name' => 'Driver',
    ]);

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'fake-token',
        'type' => 'driver',
    ]);

    $response->assertCreated()
        ->assertJson(['is_new_user' => true, 'user' => ['type' => 'driver']]);

    $user = User::where('email', 'driver@social.com')->first();
    expect($user->driver)->not->toBeNull();
    expect($user->driver->status->value)->toBe('pending_review');
});

it('logs in an existing user via social login', function () {
    $user = User::factory()->passenger()->create(['email' => 'existing@example.com']);
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => '123456',
    ]);

    mockSocialAuthService([
        'id' => '123456',
        'email' => 'existing@example.com',
        'first_name' => 'Existing',
        'last_name' => 'User',
    ]);

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'new-token',
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJson(['is_new_user' => false]);
});

it('links social account to existing email user', function () {
    $user = User::factory()->passenger()->create(['email' => 'link@example.com']);

    mockSocialAuthService([
        'id' => '999',
        'email' => 'link@example.com',
        'first_name' => 'Link',
        'last_name' => 'User',
    ]);

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'fake-token',
        'type' => 'passenger',
    ]);

    $response->assertOk()
        ->assertJson(['is_new_user' => false]);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => '999',
    ]);
});

it('rejects social login for deactivated user', function () {
    $user = User::factory()->passenger()->inactive()->create();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => '123456',
    ]);

    mockSocialAuthService([
        'id' => '123456',
        'email' => $user->email,
        'first_name' => 'Deactivated',
        'last_name' => 'User',
    ]);

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'fake-token',
        'type' => 'passenger',
    ]);

    $response->assertForbidden();
});

it('rejects social login with invalid provider', function () {
    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'twitter',
        'token' => 'fake-token',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('provider');
});

it('rejects social login with missing token', function () {
    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'type' => 'passenger',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('token');
});

it('rejects social login with invalid token', function () {
    mockSocialAuthServiceReturnsNull();

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'invalid-token',
        'type' => 'passenger',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Invalid social login token.']);
});
