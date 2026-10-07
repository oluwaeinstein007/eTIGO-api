<?php

use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher' => [
            'driver' => 'pusher',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => 'test-app',
            'options' => [
                'host' => '127.0.0.1',
                'port' => 8080,
                'scheme' => 'http',
                'useTLS' => false,
            ],
        ],
    ]);

    require base_path('routes/channels.php');
});

it('rejects broadcast auth without a token', function () {
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => 'private-driver.'.Str::uuid(),
    ])->assertUnauthorized();
});

it('allows a driver to authorize their private channel', function () {
    $user = User::factory()->driver()->create();
    $token = $user->createToken('auth', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => "private-driver.{$user->id}",
        ])->assertOk()
        ->assertJsonStructure(['auth']);
});

it('forbids a passenger from authorizing a driver channel', function () {
    $driver = User::factory()->driver()->create();
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => "private-driver.{$driver->id}",
        ])->assertForbidden();
});
