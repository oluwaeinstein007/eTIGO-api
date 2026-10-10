<?php

use App\Enums\RideStatus;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Cache;

it('returns the authenticated passenger current active ride with its details', function () {
    $passenger = User::factory()->create();
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->create(['user_id' => $driverUser->id]);
    $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id]);

    $ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $passenger->id,
        'driver_id' => $driverUser->id,
    ]);
    Cache::put("ride:{$ride->id}:pin_code", '4820', now()->addMinutes(10));

    Ride::factory()->completed()->create([
        'passenger_id' => $passenger->id,
        'driver_id' => $driverUser->id,
        'created_at' => now()->addMinute(),
    ]);

    $otherPassenger = User::factory()->create();
    Ride::factory()->inProgress()->create([
        'passenger_id' => $otherPassenger->id,
        'created_at' => now()->addMinutes(2),
    ]);

    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/passenger/active-ride');

    $response->assertOk()
        ->assertJsonPath('ride.id', $ride->id)
        ->assertJsonPath('ride.status', RideStatus::InProgress->value)
        ->assertJsonPath('ride.pin_code', '4820')
        ->assertJsonPath('ride.driver.id', $driverUser->id)
        ->assertJsonPath('ride.driver.vehicle.plate_number', $vehicle->plate_number)
        ->assertJsonStructure([
            'ride' => [
                'id',
                'status',
                'pickup',
                'destination',
                'pin_code',
                'passenger',
                'driver' => [
                    'id',
                    'first_name',
                    'last_name',
                    'phone',
                    'vehicle' => ['make', 'model', 'plate_number'],
                ],
                'state_transitions',
            ],
        ]);
});

it('returns a null ride when the passenger has no active ride', function () {
    $passenger = User::factory()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/passenger/active-ride')
        ->assertOk()
        ->assertExactJson(['ride' => null]);
});

it('requires authentication to get the current active ride', function () {
    $this->getJson('/api/v1/passenger/active-ride')->assertUnauthorized();
});

it('forbids drivers from getting a passenger active ride', function () {
    $driverUser = User::factory()->driver()->create();
    $token = $driverUser->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/passenger/active-ride')
        ->assertForbidden();
});
