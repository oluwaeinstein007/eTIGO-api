<?php

use App\Enums\RideStatus;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;

it('returns the authenticated driver current active ride with its details', function () {
    $driver = Driver::factory()->create();
    $ride = Ride::factory()->inProgress()->create([
        'driver_id' => $driver->user_id,
    ]);
    Ride::factory()->completed()->create([
        'driver_id' => $driver->user_id,
        'created_at' => now()->addMinute(),
    ]);
    $otherDriver = Driver::factory()->create();
    Ride::factory()->inProgress()->create([
        'driver_id' => $otherDriver->user_id,
        'created_at' => now()->addMinutes(2),
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/driver/active-ride');

    $response->assertOk()
        ->assertJsonPath('ride.id', $ride->id)
        ->assertJsonPath('ride.status', RideStatus::InProgress->value)
        ->assertJsonStructure([
            'ride' => ['id', 'status', 'pickup', 'destination', 'passenger', 'state_transitions'],
        ]);
});

it('returns a null ride when the driver has no active ride', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/driver/active-ride')
        ->assertOk()
        ->assertExactJson(['ride' => null]);
});

it('requires authentication to get the current active ride', function () {
    $this->getJson('/api/v1/driver/active-ride')->assertUnauthorized();
});

it('forbids passengers from getting a driver active ride', function () {
    $passenger = User::factory()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/driver/active-ride')
        ->assertForbidden();
});
