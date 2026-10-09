<?php

use App\Events\NearbyMapChanged;
use App\Models\City;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;

it('returns only online drivers with fresh nearby locations to passengers', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('passenger-auth', ['passenger'])->plainTextToken;
    $driver = Driver::factory()->online()->create();
    Vehicle::factory()->for($driver)->create();
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('findNearbyDrivers')->once()->with(6.5, 3.4, 10.0, 50)
        ->andReturn([['driver_id' => $driver->id, 'distance_km' => 1.25]]);
    $locations->shouldReceive('getDriverLocation')->once()->with($driver->id)
        ->andReturn(['lat' => 6.51, 'lng' => 3.41, 'heading' => 45, 'speed' => 0, 'timestamp' => now()->timestamp]);
    $this->app->instance(DriverLocationService::class, $locations);

    $this->withToken($token)->getJson('/api/v1/passenger/nearby-drivers?lat=6.5&lng=3.4')
        ->assertOk()
        ->assertJsonPath('drivers.0.driver_id', $driver->id)
        ->assertJsonPath('drivers.0.lat', 6.51)
        ->assertJsonPath('drivers.0.distance_km', 1.25);
});

it('returns searching ride pickups near the online driver and hides passenger details', function () {
    $city = City::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->online()->for($driverUser)->create(['city_id' => $city->id]);
    Vehicle::factory()->for($driver)->create(['vehicle_class_id' => $vehicleClass->id]);
    $ride = Ride::factory()->searching()->create([
        'city_id' => $city->id,
        'vehicle_class_id' => $vehicleClass->id,
        'pickup_lat' => 6.5,
        'pickup_lng' => 3.4,
    ]);
    $token = $driverUser->createToken('driver-auth', ['driver'])->plainTextToken;
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->once()->with($driver->id)
        ->andReturn(['lat' => 6.5, 'lng' => 3.4, 'heading' => 0, 'speed' => 0, 'timestamp' => now()->timestamp]);
    $this->app->instance(DriverLocationService::class, $locations);

    $this->withToken($token)->getJson('/api/v1/driver/nearby-rides')
        ->assertOk()
        ->assertJsonPath('rides.0.ride_id', $ride->id)
        ->assertJsonPath('rides.0.pickup_lat', 6.5)
        ->assertJsonMissingPath('rides.0.passenger_id');
});

it('requires an online driver location before returning nearby rides', function () {
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->approved()->for($driverUser)->create(['is_online' => false]);
    $token = $driverUser->createToken('driver-auth', ['driver'])->plainTextToken;
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldNotReceive('getDriverLocation');
    $this->app->instance(DriverLocationService::class, $locations);

    $this->withToken($token)->getJson('/api/v1/driver/nearby-rides')
        ->assertOk()
        ->assertExactJson(['rides' => []]);
});

it('broadcasts map invalidations without exposing location or passenger data', function () {
    $passengers = new NearbyMapChanged('passengers');
    $drivers = new NearbyMapChanged('drivers');

    expect($passengers->broadcastOn()[0]->name)->toBe('private-passengers.nearby');
    expect($drivers->broadcastOn()[0]->name)->toBe('private-drivers.nearby');
    expect($passengers->broadcastAs())->toBe('nearby.map.changed');
    expect($passengers->broadcastWith())->toHaveKeys(['changed_at']);
    expect($passengers->broadcastWith())->not->toHaveKeys(['lat', 'lng', 'ride_id', 'passenger_id']);
});
