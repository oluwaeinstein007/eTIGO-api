<?php

use App\Contracts\MapsGateway;
use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\DispatchRideRequestJob;
use App\Jobs\MatchingTimeoutJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverMatchingService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 600,
        'per_km_rate' => 250,
        'per_minute_rate' => 40,
        'minimum_fare' => 1500,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => User::factory()->admin()->create()->id,
    ]);

    $mockMaps = Mockery::mock(MapsGateway::class);
    $mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $mockMaps->shouldReceive('reverseGeocode')
        ->andReturn(['address' => '123 Test Street, Lagos']);
    $this->app->instance(MapsGateway::class, $mockMaps);
});

function createOnlineDriver(object $testContext, ?VehicleClass $vehicleClass = null): array
{
    $user = User::factory()->create(['type' => UserType::Driver]);
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::Approved,
        'is_online' => true,
        'city_id' => $testContext->city->id,
    ]);
    Vehicle::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_class_id' => $vehicleClass?->id ?? $testContext->vehicleClass->id,
    ]);
    $token = $user->createToken('auth', ['driver'])->plainTextToken;

    return ['user' => $user, 'driver' => $driver, 'token' => $token];
}

function createSearchingRide(object $testContext): Ride
{
    return Ride::factory()->create([
        'city_id' => $testContext->city->id,
        'vehicle_class_id' => $testContext->vehicleClass->id,
        'passenger_id' => $testContext->passenger->id,
        'status' => RideStatus::Searching,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'pickup_address' => '123 Test Street',
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'destination_address' => '456 Dest Street',
        'fare_estimate_amount' => 3650.00,
        'fare_currency' => 'NGN',
        'payment_method' => 'cash',
    ]);
}

// === RIDE CREATION DISPATCHES MATCHING ===

it('dispatches matching jobs when a ride is created', function () {
    $response = $this->withToken($this->passengerToken)
        ->postJson('/api/v1/rides', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'pickup_address' => '123 Test Street',
            'destination_lat' => 6.4541,
            'destination_lng' => 3.3947,
            'destination_address' => '456 Dest Street',
            'payment_method' => 'cash',
        ]);

    $response->assertCreated();

    Queue::assertPushed(DispatchRideRequestJob::class);
    Queue::assertPushed(MatchingTimeoutJob::class);
});

// === DRIVER ACCEPT ===

it('allows a dispatched driver to accept a ride', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);

    $matchingService = app(DriverMatchingService::class);
    $matchingService->setDispatchedDriver($ride, $d['user']->id);

    $response = $this->withToken($d['token'])
        ->postJson("/api/v1/rides/{$ride->id}/accept");

    $response->assertOk()
        ->assertJsonPath('message', 'Ride accepted.');

    $ride->refresh();
    expect($ride->status)->toBe(RideStatus::DriverEnRoute);
    expect($ride->driver_id)->toBe($d['user']->id);
});

it('rejects acceptance from a driver not dispatched to the ride', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);

    $response = $this->withToken($d['token'])
        ->postJson("/api/v1/rides/{$ride->id}/accept");

    $response->assertForbidden();
});

it('rejects acceptance if ride is no longer searching', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);
    $ride->update(['status' => RideStatus::Cancelled]);

    $matchingService = app(DriverMatchingService::class);
    $matchingService->setDispatchedDriver($ride, $d['user']->id);

    $response = $this->withToken($d['token'])
        ->postJson("/api/v1/rides/{$ride->id}/accept");

    $response->assertUnprocessable();
});

// === DRIVER REJECT ===

it('allows a driver to reject a ride and re-dispatches', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);

    $matchingService = app(DriverMatchingService::class);
    $matchingService->setDispatchedDriver($ride, $d['user']->id);

    $response = $this->withToken($d['token'])
        ->postJson("/api/v1/rides/{$ride->id}/reject");

    $response->assertOk();

    Queue::assertPushed(DispatchRideRequestJob::class);

    $rejected = $matchingService->getRejectedDriverIds($ride);
    expect($rejected)->toContain($d['user']->id);
});

// === ADMIN MANUAL ASSIGN ===

it('allows admin to manually assign a driver to a ride', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = $admin->createToken('auth', ['admin'])->plainTextToken;

    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);

    $response = $this->withToken($adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", [
            'driver_id' => $d['user']->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Driver assigned successfully.');

    $ride->refresh();
    expect($ride->status)->toBe(RideStatus::DriverEnRoute);
    expect($ride->driver_id)->toBe($d['user']->id);
});

it('rejects admin assign when driver has wrong vehicle class', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = $admin->createToken('auth', ['admin'])->plainTextToken;

    $otherClass = VehicleClass::factory()->create();
    $d = createOnlineDriver($this, $otherClass);
    $ride = createSearchingRide($this);

    $response = $this->withToken($adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", [
            'driver_id' => $d['user']->id,
        ]);

    $response->assertUnprocessable();
});

it('rejects admin assign when driver is offline', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = $admin->createToken('auth', ['admin'])->plainTextToken;

    $d = createOnlineDriver($this);
    $d['driver']->update(['is_online' => false]);
    $ride = createSearchingRide($this);

    $response = $this->withToken($adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", [
            'driver_id' => $d['user']->id,
        ]);

    $response->assertUnprocessable();
});

it('rejects admin assign when driver already has an active ride', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = $admin->createToken('auth', ['admin'])->plainTextToken;

    $d = createOnlineDriver($this);

    Ride::factory()->create([
        'driver_id' => $d['user']->id,
        'passenger_id' => User::factory()->create(['type' => UserType::Passenger])->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::InProgress,
    ]);

    $ride = createSearchingRide($this);

    $response = $this->withToken($adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", [
            'driver_id' => $d['user']->id,
        ]);

    $response->assertUnprocessable();
});

// === MATCHING SERVICE UNIT ===

it('tracks rejected and dispatched driver state correctly', function () {
    $ride = createSearchingRide($this);
    $service = app(DriverMatchingService::class);

    $driverId = '00000000-0000-4000-8000-000000000042';
    $service->setDispatchedDriver($ride, $driverId);
    expect($service->getDispatchedDriverId($ride))->toBe($driverId);

    $service->markDriverRejected($ride, $driverId);
    expect($service->getRejectedDriverIds($ride))->toContain($driverId);

    $service->clearDispatchedDriver($ride);
    expect($service->getDispatchedDriverId($ride))->toBeNull();

    $service->cleanupRideCache($ride);
    expect($service->getRejectedDriverIds($ride))->toBeEmpty();
});

// === CONFIG ===

it('has sensible matching config defaults', function () {
    expect(config('matching.initial_radius_km'))->toBe(3.0);
    expect(config('matching.max_radius_km'))->toBe(15.0);
    expect(config('matching.driver_response_timeout'))->toBe(20);
    expect(config('matching.matching_timeout'))->toBe(180);
});
