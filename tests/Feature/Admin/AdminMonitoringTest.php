<?php

use App\Enums\AdminRole;
use App\Enums\RideStatus;
use App\Models\City;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;

beforeEach(function () {
    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->adminToken = $this->admin->createToken('admin-token', ['admin'])->plainTextToken;

    $this->city = City::factory()->create();
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    $this->mockLocationService = Mockery::mock(DriverLocationService::class);
    $this->app->instance(DriverLocationService::class, $this->mockLocationService);
});

it('lists active rides with driver locations and summary', function () {
    $passenger = User::factory()->passenger()->create();
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->approved()->for($driverUser)->create([
        'city_id' => $this->city->id,
        'is_online' => true,
    ]);
    Vehicle::factory()->for($driver)->create([
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $activeRide = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'driver_id' => $driverUser->id,
        'status' => RideStatus::DriverEnRoute,
    ]);

    Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'driver_id' => $driverUser->id,
        'status' => RideStatus::Completed,
    ]);

    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->andReturn([
            'lat' => 6.5244,
            'lng' => 3.3792,
            'heading' => 45.0,
            'speed' => 30.0,
            'timestamp' => now()->timestamp,
        ]);

    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/monitoring/active-rides');

    $response->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('rides.0.id', $activeRide->id)
        ->assertJsonPath('rides.0.driver_location.lat', 6.5244)
        ->assertJsonPath('summary.total_active', 1)
        ->assertJsonPath('summary.by_status.driver_en_route', 1);
});

it('filters active rides by city and status', function () {
    $passenger = User::factory()->passenger()->create();
    $otherCity = City::factory()->create();

    Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'status' => RideStatus::Searching,
    ]);

    Ride::factory()->create([
        'city_id' => $otherCity->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'status' => RideStatus::Searching,
    ]);

    $response = $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/monitoring/active-rides?city_id={$this->city->id}&status=searching");

    $response->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('summary.total_active', 1);
});

it('lists online drivers with location and trip status', function () {
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->approved()->for($driverUser)->create([
        'city_id' => $this->city->id,
        'is_online' => true,
    ]);
    Vehicle::factory()->for($driver)->create([
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->with((string) $driver->id)
        ->andReturn([
            'lat' => 6.5244,
            'lng' => 3.3792,
            'heading' => 90.0,
            'speed' => 0.0,
            'timestamp' => now()->timestamp,
        ]);

    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/monitoring/online-drivers');

    $response->assertOk()
        ->assertJsonCount(1, 'drivers')
        ->assertJsonPath('drivers.0.driver_id', $driver->id)
        ->assertJsonPath('drivers.0.location.lat', 6.5244)
        ->assertJsonPath('drivers.0.is_on_trip', false)
        ->assertJsonPath('summary.total_online', 1)
        ->assertJsonPath('summary.available', 1)
        ->assertJsonPath('summary.on_trip', 0);
});

it('lists pending unassigned rides ordered oldest first', function () {
    $passenger = User::factory()->passenger()->create();

    $olderRide = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'driver_id' => null,
        'status' => RideStatus::Requested,
        'created_at' => now()->subMinutes(10),
    ]);

    $newerRide = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'driver_id' => null,
        'status' => RideStatus::Searching,
        'created_at' => now()->subMinutes(2),
    ]);

    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/rides/pending');

    $response->assertOk()
        ->assertJsonCount(2, 'rides')
        ->assertJsonPath('rides.0.id', $olderRide->id)
        ->assertJsonPath('rides.1.id', $newerRide->id);
});

it('finds available drivers for a ride by redis proximity and vehicle class', function () {
    $passenger = User::factory()->passenger()->create();
    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'driver_id' => null,
        'status' => RideStatus::Searching,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
    ]);

    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->approved()->for($driverUser)->create([
        'city_id' => $this->city->id,
        'is_online' => true,
    ]);
    Vehicle::factory()->for($driver)->create([
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $this->mockLocationService->shouldReceive('findNearbyDrivers')
        ->with(6.5244, 3.3792, 5.0, 500)
        ->andReturn([
            [
                'driver_id' => $driver->id,
                'distance_km' => 1.85,
            ],
        ]);

    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->with((string) $driver->id)
        ->andReturn([
            'lat' => 6.5300,
            'lng' => 3.3850,
            'heading' => 0.0,
            'speed' => 0.0,
            'timestamp' => now()->timestamp,
        ]);

    $response = $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/rides/{$ride->id}/available-drivers");

    $response->assertOk()
        ->assertJsonCount(1, 'drivers')
        ->assertJsonPath('drivers.0.driver_id', $driver->id)
        ->assertJsonPath('drivers.0.distance_km', 1.85)
        ->assertJsonPath('drivers.0.location.lat', 6.5300);
});
