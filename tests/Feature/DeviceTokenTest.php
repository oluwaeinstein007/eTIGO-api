<?php

use App\Models\DeviceToken;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth')->plainTextToken;
});

it('registers a device token', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-abc123',
            'platform' => 'android',
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'Device token registered.']);

    $this->assertDatabaseHas('device_tokens', [
        'user_id' => $this->user->id,
        'token' => 'fcm-token-abc123',
        'platform' => 'android',
        'is_active' => true,
    ]);
});

it('does not duplicate an existing device token', function () {
    DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'fcm-token-abc123',
        'platform' => 'android',
    ]);

    $this->withToken($this->token)
        ->postJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-abc123',
            'platform' => 'android',
        ]);

    expect(DeviceToken::where('user_id', $this->user->id)->count())->toBe(1);
});

it('removes a device token', function () {
    DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'fcm-token-abc123',
        'platform' => 'android',
    ]);

    $response = $this->withToken($this->token)
        ->deleteJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-abc123',
            'platform' => 'android',
        ]);

    $response->assertOk();
    $this->assertDatabaseMissing('device_tokens', ['token' => 'fcm-token-abc123']);
});

it('validates required fields when registering a token', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/device-tokens', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['token', 'platform']);
});

it('validates platform must be ios, android, or web', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-abc123',
            'platform' => 'blackberry',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['platform']);
});

it('returns 401 when unauthenticated', function () {
    $response = $this->postJson('/api/v1/device-tokens', [
        'token' => 'fcm-token-abc123',
        'platform' => 'android',
    ]);

    $response->assertUnauthorized();
});
