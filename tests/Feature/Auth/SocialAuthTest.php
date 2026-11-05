<?php

use App\Models\SocialAccount;
use App\Models\SocialAuthCode;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeSocialiteUser(string $id = '123456', string $email = 'social@example.com', string $name = 'Social User'): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->email = $email;
    $user->name = $name;
    $user->avatar = 'https://example.com/avatar.jpg';
    $user->token = 'fake-access-token';
    $user->refreshToken = 'fake-refresh-token';

    return $user;
}

function mockSocialiteDriver(SocialiteUser $socialiteUser, string $provider = 'google'): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('userFromToken')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

it('creates a new passenger via Google social login', function () {
    $socialiteUser = fakeSocialiteUser();
    mockSocialiteDriver($socialiteUser);

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
    $socialiteUser = fakeSocialiteUser('789', 'driver@social.com', 'Social Driver');
    mockSocialiteDriver($socialiteUser);

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'fake-token',
        'type' => 'driver',
    ]);

    $response->assertCreated()
        ->assertJson(['is_new_user' => true, 'user' => ['type' => 'driver']]);

    $user = User::where('email', 'driver@social.com')->first();
    expect($user->driver)->not->toBeNull();
    expect($user->driver->status->value)->toBe('onboarding');
});

it('logs in an existing user via social login', function () {
    $user = User::factory()->passenger()->create(['email' => 'existing@example.com']);
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => '123456',
    ]);

    $socialiteUser = fakeSocialiteUser('123456', 'existing@example.com');
    mockSocialiteDriver($socialiteUser);

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

    $socialiteUser = fakeSocialiteUser('999', 'link@example.com', 'Link User');
    mockSocialiteDriver($socialiteUser);

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

    $socialiteUser = fakeSocialiteUser('123456', $user->email);
    mockSocialiteDriver($socialiteUser);

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
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('userFromToken')->andThrow(new Exception('Invalid token'));

    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $response = $this->postJson('/api/v1/auth/social', [
        'provider' => 'google',
        'token' => 'invalid-token',
        'type' => 'passenger',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Invalid social login token.']);
});

function pendingDeletionPassenger(string $email): User
{
    $user = User::factory()->passenger()->create(['email' => $email]);
    $user->forceFill(['deletion_requested_at' => now()->subDays(3), 'deletion_scheduled_for' => now()->addDays(27)])->save();

    return $user;
}

function socialExchangeCode(string $providerId, string $email): string
{
    $code = str_repeat('a', 64);
    SocialAuthCode::create([
        'code' => $code,
        'provider' => 'google',
        'provider_id' => $providerId,
        'email' => $email,
        'user_type' => 'passenger',
        'state' => 'state',
        'expires_at' => now()->addMinutes(5),
    ]);

    return $code;
}

it('cancels a pending deletion on social login with a linked account', function () {
    $user = pendingDeletionPassenger('linked@example.com');
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => '555']);
    mockSocialiteDriver(fakeSocialiteUser('555', 'linked@example.com'));

    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'token' => 't', 'type' => 'passenger'])
        ->assertOk()->assertJson(['account_restored' => true]);

    expect($user->fresh()->deletion_requested_at)->toBeNull();
});

it('cancels a pending deletion on social login matched by email', function () {
    $user = pendingDeletionPassenger('match@example.com');
    mockSocialiteDriver(fakeSocialiteUser('556', 'match@example.com'));

    $this->postJson('/api/v1/auth/social', ['provider' => 'google', 'token' => 't', 'type' => 'passenger'])
        ->assertOk()->assertJson(['account_restored' => true]);

    expect($user->fresh()->deletion_requested_at)->toBeNull();
});

it('cancels a pending deletion on social code exchange with a linked account', function () {
    $user = pendingDeletionPassenger('exlinked@example.com');
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => '557']);

    $this->postJson('/api/v1/auth/social/exchange', ['code' => socialExchangeCode('557', 'exlinked@example.com')])
        ->assertOk()->assertJson(['account_restored' => true]);

    expect($user->fresh()->deletion_requested_at)->toBeNull();
});

it('cancels a pending deletion on social code exchange matched by email', function () {
    $user = pendingDeletionPassenger('exmatch@example.com');

    $this->postJson('/api/v1/auth/social/exchange', ['code' => socialExchangeCode('558', 'exmatch@example.com')])
        ->assertOk()->assertJson(['account_restored' => true]);

    expect($user->fresh()->deletion_requested_at)->toBeNull();
});
