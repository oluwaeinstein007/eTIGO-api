<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class);

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

test('broadcast authentication rejects requests without a Sanctum token', function () {
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => 'private-driver.1',
    ])->assertUnauthorized();
});

test('a driver can authorize its private driver channel', function () {
    Sanctum::actingAs(User::factory()->driver()->make()->forceFill(['id' => 1]));

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => 'private-driver.1',
    ])->assertOk()
        ->assertJsonStructure(['auth']);
});

test('a passenger cannot authorize a private driver channel', function () {
    Sanctum::actingAs(User::factory()->passenger()->make()->forceFill(['id' => 1]));

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => 'private-driver.1',
    ])->assertForbidden();
});
