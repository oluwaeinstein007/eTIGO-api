<?php

use App\Contracts\MapsGateway;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->driver()->create();
    $this->driver = Driver::factory()->approved()->for($this->driverUser)->create(['is_online' => true]);
    Vehicle::factory()->for($this->driver)->create();
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->admin = User::factory()->admin()->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    $mockMaps = Mockery::mock(MapsGateway::class);
    $mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 3.2, 'duration_minutes' => 8.0]);
    $this->app->instance(MapsGateway::class, $mockMaps);

    $this->mockLocationService = Mockery::mock(DriverLocationService::class);
    $this->app->instance(DriverLocationService::class, $this->mockLocationService);

    $this->ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'status' => RideStatus::DriverEnRoute,
    ]);
});

it('returns driver location and eta for active ride', function () {
    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->with($this->driverUser->id)
        ->andReturn([
            'lat' => 9.0579,
            'lng' => 7.4951,
            'heading' => 90.0,
            'speed' => 25.0,
            'timestamp' => now()->timestamp,
        ]);

    $response = $this->withToken($this->passengerToken)
        ->getJson("/api/v1/rides/{$this->ride->id}/location");

    $response->assertOk()
        ->assertJsonStructure([
            'location' => ['lat', 'lng', 'heading', 'speed', 'timestamp'],
            'eta' => ['distance_km', 'duration_minutes'],
            'ride_status',
        ])
        ->assertJsonPath('ride_status', 'driver_en_route');
});

it('returns null location when driver has no cached position', function () {
    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->with($this->driverUser->id)
        ->andReturnNull();

    $response = $this->withToken($this->passengerToken)
        ->getJson("/api/v1/rides/{$this->ride->id}/location");

    $response->assertOk()
        ->assertJson([
            'message' => 'Driver location unavailable.',
            'location' => null,
            'eta' => null,
        ]);
});

it('returns null when no driver assigned', function () {
    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => null,
        'status' => RideStatus::Searching,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->getJson("/api/v1/rides/{$ride->id}/location");

    $response->assertOk()
        ->assertJson(['location' => null, 'eta' => null]);
});

it('allows the assigned driver to view location', function () {
    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->andReturn([
            'lat' => 9.0579,
            'lng' => 7.4951,
            'heading' => 0.0,
            'speed' => 0.0,
            'timestamp' => now()->timestamp,
        ]);

    $response = $this->withToken($this->driverToken)
        ->getJson("/api/v1/rides/{$this->ride->id}/location");

    $response->assertOk()
        ->assertJsonStructure(['location', 'eta', 'ride_status']);
});

it('allows admin to view any ride location', function () {
    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->andReturn([
            'lat' => 9.0579,
            'lng' => 7.4951,
            'heading' => 0.0,
            'speed' => 0.0,
            'timestamp' => now()->timestamp,
        ]);

    $response = $this->withToken($this->adminToken)
        ->getJson("/api/v1/rides/{$this->ride->id}/location");

    $response->assertOk();
});

it('returns 403 for unrelated passenger', function () {
    $otherUser = User::factory()->create(['type' => UserType::Passenger]);
    $otherToken = $otherUser->createToken('auth', ['passenger'])->plainTextToken;

    $response = $this->withToken($otherToken)
        ->getJson("/api/v1/rides/{$this->ride->id}/location");

    $response->assertForbidden();
});

it('returns 401 without authentication', function () {
    $response = $this->getJson("/api/v1/rides/{$this->ride->id}/location");

    $response->assertUnauthorized();
});
