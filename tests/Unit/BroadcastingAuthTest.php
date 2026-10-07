<?php

use App\Models\User;
use Illuminate\Support\Str;
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
    $uuid = Str::uuid()->toString();
    $this->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => "private-driver.{$uuid}",
    ])->assertUnauthorized();
});

test('a driver can authorize its private driver channel', function () {
    $uuid = Str::uuid()->toString();
    Sanctum::actingAs(User::factory()->driver()->make()->forceFill(['id' => $uuid]));

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => "private-driver.{$uuid}",
    ])->assertOk()
        ->assertJsonStructure(['auth']);
});

test('a passenger cannot authorize a private driver channel', function () {
    $driverUuid = Str::uuid()->toString();
    $passengerUuid = Str::uuid()->toString();
    Sanctum::actingAs(User::factory()->passenger()->make()->forceFill(['id' => $passengerUuid]));

    $this->postJson('/broadcasting/auth', [
        'socket_id' => '12345.67890',
        'channel_name' => "private-driver.{$driverUuid}",
    ])->assertForbidden();
});
