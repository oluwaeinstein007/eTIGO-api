<?php

use App\Contracts\MapsGateway;
use App\Enums\AdminRole;
use App\Enums\RideStatus;
use App\Enums\SosIncidentStatus;
use App\Enums\SosTriggerType;
use App\Models\City;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use Illuminate\Support\Facades\Cache;

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

it('shows eta, the en-route time and who raised an open sos on active rides', function () {
    $this->freezeTime();

    $passenger = User::factory()->passenger()->create(['first_name' => 'Seyi', 'last_name' => 'Adeniran']);
    $driverUser = User::factory()->driver()->create();
    $driver = Driver::factory()->approved()->for($driverUser)->create(['city_id' => $this->city->id, 'is_online' => true]);

    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $passenger->id,
        'driver_id' => $driverUser->id,
        'status' => RideStatus::DriverEnRoute,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
    ]);
    $ride->stateTransitions()->create([
        'from_state' => RideStatus::Matched,
        'to_state' => RideStatus::DriverEnRoute,
        'triggered_by_type' => 'driver',
        'triggered_by_id' => $driverUser->id,
        'created_at' => now()->subMinutes(4),
    ]);
    SosIncident::forceCreate([
        'ride_id' => $ride->id,
        'triggered_by_user_id' => $passenger->id,
        'trigger_type' => SosTriggerType::Passenger,
        'status' => SosIncidentStatus::Triggered,
        'gps_lat' => 6.5244,
        'gps_lng' => 3.3792,
        'created_at' => now()->subMinute(),
    ]);

    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->with((string) $driver->id)
        ->andReturn(['lat' => 6.5344, 'lng' => 3.3792, 'heading' => 180.0, 'speed' => 25.0, 'timestamp' => now()->timestamp]);

    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/monitoring/active-rides');

    $response->assertOk()
        ->assertJsonPath('rides.0.driver_location.updated_at', now()->toIso8601String())
        ->assertJsonPath('rides.0.eta.target', 'pickup')
        ->assertJsonPath('rides.0.eta.distance_km', 1.45)
        ->assertJsonPath('rides.0.eta.duration_minutes', 2.9)
        ->assertJsonPath('rides.0.stage_timestamps.driver_en_route_at', now()->subMinutes(4)->toIso8601String())
        ->assertJsonPath('rides.0.sos.raised_by', [
            'role' => 'passenger',
            'user_id' => $passenger->id,
            'name' => 'Seyi Adeniran',
        ]);
});

it('prefers the eta last computed from the driver location over the estimate', function () {
    $driverUser = User::factory()->driver()->create();
    Driver::factory()->approved()->for($driverUser)->create(['city_id' => $this->city->id, 'is_online' => true]);

    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $driverUser->id,
        'status' => RideStatus::InProgress,
    ]);
    Cache::put("ride_eta:{$ride->id}:in_progress", ['distance_km' => 7.2, 'duration_minutes' => 18.0], 30);

    $this->mockLocationService->shouldReceive('getDriverLocation')
        ->andReturn(['lat' => 6.5, 'lng' => 3.3, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp]);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/monitoring/active-rides')
        ->assertOk()
        ->assertJsonPath('rides.0.eta', ['target' => 'destination', 'distance_km' => 7.2, 'duration_minutes' => 18]);
});

it('returns a ride with its planned route', function () {
    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Searching,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
    ]);

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getRoute')
        ->once()
        ->with(6.5244, 3.3792, 6.4541, 3.3947)
        ->andReturn(['encoded_polyline' => '_p~iF~ps|U_ulLnnqC', 'distance_km' => 9.8, 'duration_minutes' => 24.5]);
    $this->app->instance(MapsGateway::class, $maps);

    foreach ([1, 2] as $request) {
        $this->withToken($this->adminToken)
            ->getJson("/api/v1/admin/monitoring/rides/{$ride->id}")
            ->assertOk()
            ->assertJsonPath('ride.id', $ride->id)
            ->assertJsonPath('ride.route', ['encoded_polyline' => '_p~iF~ps|U_ulLnnqC', 'distance_km' => 9.8, 'duration_minutes' => 24.5]);
    }
});

it('returns the ride without a route when routing fails', function () {
    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Searching,
    ]);

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getRoute')->andThrow(new RuntimeException('Routes API unavailable'));
    $this->app->instance(MapsGateway::class, $maps);

    $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/monitoring/rides/{$ride->id}")
        ->assertOk()
        ->assertJsonPath('ride.route', null);
});

it('searches pending rides by city and vehicle class name', function () {
    $otherCity = City::factory()->create(['name' => 'Ibadan']);
    $minibus = VehicleClass::factory()->create(['name' => 'minibus', 'display_name' => 'Minibus']);

    $inIbadan = Ride::factory()->create(['city_id' => $otherCity->id, 'vehicle_class_id' => $this->vehicleClass->id, 'status' => RideStatus::Searching]);
    $minibusRide = Ride::factory()->create(['city_id' => $this->city->id, 'vehicle_class_id' => $minibus->id, 'status' => RideStatus::Searching]);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/rides/pending?search=ibad')
        ->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('rides.0.id', $inIbadan->id);

    $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/rides/pending?search=MINIB')
        ->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('rides.0.id', $minibusRide->id);
});

it('counts the rides awaiting a driver for the needs attention badge', function () {
    $attributes = ['city_id' => $this->city->id, 'vehicle_class_id' => $this->vehicleClass->id];

    Ride::factory()->create($attributes + ['status' => RideStatus::Searching]);
    Ride::factory()->create($attributes + ['status' => RideStatus::NoDriverFound]);
    Ride::factory()->driverEnRoute()->create($attributes);
    Ride::factory()->create(['vehicle_class_id' => $this->vehicleClass->id, 'status' => RideStatus::Searching]);

    $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/rides/pending?city_id={$this->city->id}&status=driver_en_route")
        ->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('summary.awaiting_driver', 2)
        ->assertJsonPath('summary.by_status.searching', 1)
        ->assertJsonPath('summary.by_status.no_driver_found', 1)
        ->assertJsonPath('summary.by_status.driver_en_route', 1);
});

it('drops rides that found no driver from the pending list once the assignment window has passed', function () {
    $attributes = ['city_id' => $this->city->id, 'vehicle_class_id' => $this->vehicleClass->id];

    $recent = Ride::factory()->create($attributes + ['status' => RideStatus::NoDriverFound, 'created_at' => now()->subHours(23)]);
    Ride::factory()->create($attributes + ['status' => RideStatus::NoDriverFound, 'created_at' => now()->subHours(25)]);
    $stuckSearching = Ride::factory()->create($attributes + ['status' => RideStatus::Searching, 'created_at' => now()->subHours(30)]);

    $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/rides/pending');

    $response->assertOk()
        ->assertJsonPath('summary.awaiting_driver', 2)
        ->assertJsonPath('summary.by_status.no_driver_found', 1);
    expect(collect($response->json('rides'))->pluck('id')->all())->toBe([$stuckSearching->id, $recent->id]);
});
