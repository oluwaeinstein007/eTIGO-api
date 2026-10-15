<?php

use App\Contracts\MapsGateway;
use App\Enums\AccountType;
use App\Enums\DriverStatus;
use App\Enums\HoldStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\DispatchRideRequestJob;
use App\Jobs\DriverResponseTimeoutJob;
use App\Jobs\MatchingTimeoutJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\GamificationProfile;
use App\Models\Hold;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\DriverMatchingService;
use App\Services\LedgerService;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SystemAccountSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->city = City::factory()->create(['currency_code' => 'NGN']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    $this->admin = User::factory()->admin()->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $this->app->instance(MapsGateway::class, $maps);
});

function onlineDriver(object $test): array
{
    $user = User::factory()->create(['type' => UserType::Driver]);
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::Approved,
        'is_online' => true,
        'city_id' => $test->city->id,
    ]);
    Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => $test->vehicleClass->id]);

    return ['user' => $user, 'driver' => $driver, 'token' => $user->createToken('auth', ['driver'])->plainTextToken];
}

function rideInStatus(object $test, RideStatus $status, array $overrides = []): Ride
{
    return Ride::factory()->create(array_merge([
        'city_id' => $test->city->id,
        'vehicle_class_id' => $test->vehicleClass->id,
        'passenger_id' => User::factory()->create(['type' => UserType::Passenger])->id,
        'status' => $status,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'fare_estimate_amount' => 3650.00,
        'payment_method' => 'cash',
    ], $overrides));
}

it('returns 409 when a driver tries to accept a second ride while one is active', function () {
    $d = onlineDriver($this);
    rideInStatus($this, RideStatus::DriverEnRoute, ['driver_id' => $d['user']->id]);
    $second = rideInStatus($this, RideStatus::Searching);
    app(DriverMatchingService::class)->setDispatchedDriver($second, $d['user']->id);

    $this->withToken($d['token'])
        ->postJson("/api/v1/rides/{$second->id}/accept")
        ->assertConflict()
        ->assertJsonPath('message', 'You already have an active ride. Complete it before accepting another.');

    expect($second->fresh()->status)->toBe(RideStatus::Searching)
        ->and($second->fresh()->driver_id)->toBeNull();
});

it('forbids an offline driver from accepting a dispatched ride', function () {
    $d = onlineDriver($this);
    $d['driver']->update(['is_online' => false]);
    $ride = rideInStatus($this, RideStatus::Searching);
    app(DriverMatchingService::class)->setDispatchedDriver($ride, $d['user']->id);

    $this->withToken($d['token'])
        ->postJson("/api/v1/rides/{$ride->id}/accept")
        ->assertForbidden();

    expect($ride->fresh()->status)->toBe(RideStatus::Searching);
});

it('does not offer a ride to a driver holding an unanswered offer for another ride', function () {
    $busy = onlineDriver($this);
    $free = onlineDriver($this);
    $otherRide = rideInStatus($this, RideStatus::Searching);
    $ride = rideInStatus($this, RideStatus::Searching);
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([
        ['driver_id' => $busy['driver']->id, 'distance_km' => 0.5],
        ['driver_id' => $free['driver']->id, 'distance_km' => 2.0],
    ]);
    $matching = new DriverMatchingService($locations);
    $matching->setDispatchedDriver($otherRide, $busy['user']->id);

    $candidates = $matching->findEligibleDrivers($ride, 3.0);

    expect(array_column($candidates, 'user_id'))->toBe([$free['user']->id]);
});

it('moves the ride to the next driver when the offer key expired under queue lag', function () {
    $d = onlineDriver($this);
    $ride = rideInStatus($this, RideStatus::Searching);
    $matching = app(DriverMatchingService::class);

    (new DriverResponseTimeoutJob($ride->id, $d['user']->id))->handle($matching);

    Queue::assertPushed(DispatchRideRequestJob::class, fn ($job) => $job->rideId === $ride->id);
    expect($matching->getRejectedDriverIds($ride))->toContain($d['user']->id);
});

it('does not re-dispatch from the timeout when the driver already rejected the offer', function () {
    $d = onlineDriver($this);
    $ride = rideInStatus($this, RideStatus::Searching);
    $matching = app(DriverMatchingService::class);
    $matching->markDriverRejected($ride, $d['user']->id);

    (new DriverResponseTimeoutJob($ride->id, $d['user']->id))->handle($matching);

    Queue::assertNotPushed(DispatchRideRequestJob::class);
});

it('ignores a matching timeout left over from an earlier search attempt', function () {
    $ride = rideInStatus($this, RideStatus::Searching);
    foreach (['requested', 'no_driver_found'] as $from) {
        RideStateTransition::create([
            'ride_id' => $ride->id, 'from_state' => $from, 'to_state' => 'searching',
            'triggered_by_type' => 'system', 'created_at' => now(),
        ]);
    }

    app()->call([new MatchingTimeoutJob($ride->id, 1), 'handle']);

    expect($ride->fresh()->status)->toBe(RideStatus::Searching);
});

it('ends the current search when its own matching timeout fires', function () {
    $ride = rideInStatus($this, RideStatus::Searching);
    RideStateTransition::create([
        'ride_id' => $ride->id, 'from_state' => 'requested', 'to_state' => 'searching',
        'triggered_by_type' => 'system', 'created_at' => now(),
    ]);

    app()->call([new MatchingTimeoutJob($ride->id, 1), 'handle']);

    expect($ride->fresh()->status)->toBe(RideStatus::NoDriverFound);
});

it('lets an admin assign a driver to a ride that ended with no driver found', function () {
    $d = onlineDriver($this);
    $ride = rideInStatus($this, RideStatus::NoDriverFound, ['passenger_id' => $this->passenger->id]);

    $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", ['driver_id' => $d['user']->id])
        ->assertOk()
        ->assertJsonPath('ride.status', 'driver_en_route');

    expect($ride->fresh()->driver_id)->toBe($d['user']->id);
});

it('re-places the wallet hold when assigning a wallet ride that ended with no driver found', function () {
    $this->seed(SystemAccountSeeder::class);
    $d = onlineDriver($this);
    $ride = rideInStatus($this, RideStatus::NoDriverFound, [
        'passenger_id' => $this->passenger->id,
        'payment_method' => 'wallet',
        'fare_estimate_amount' => 3000.00,
    ]);
    $wallet = app(LedgerService::class)->findOrCreateAccount('App\\Models\\User', $this->passenger->id, AccountType::PassengerWallet);
    $wallet->update(['balance' => 500000]);

    $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", ['driver_id' => $d['user']->id])
        ->assertOk();

    expect(Hold::where('ride_id', $ride->id)->where('status', HoldStatus::Active)->value('amount'))->toBe(300000);
});

it('refuses to assign a no-driver-found ride when the passenger already booked another ride', function () {
    $d = onlineDriver($this);
    $ride = rideInStatus($this, RideStatus::NoDriverFound, ['passenger_id' => $this->passenger->id]);
    rideInStatus($this, RideStatus::Searching, ['passenger_id' => $this->passenger->id]);

    $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", ['driver_id' => $d['user']->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ride' => 'The passenger already has another active ride.']);

    expect($ride->fresh()->status)->toBe(RideStatus::NoDriverFound);
});

// === Priority matching (Gold, Platinum, Diamond passengers) ===

it('keeps a driver near a waiting Gold passenger for that passenger rather than a standard ride', function () {
    $driver = onlineDriver($this);
    $priority = rideInStatus($this, RideStatus::Searching, ['pickup_lat' => 6.5244, 'pickup_lng' => 3.3792, 'pricing_snapshot' => ['priority_matching' => true]]);
    $standard = rideInStatus($this, RideStatus::Searching, ['pickup_lat' => 6.5300, 'pickup_lng' => 3.3800]);
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([['driver_id' => $driver['driver']->id, 'distance_km' => 0.8]]);
    $locations->shouldReceive('getDriverLocation')->andReturn(['lat' => 6.5250, 'lng' => 3.3795, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp]);
    $matching = new DriverMatchingService($locations);

    expect($matching->findEligibleDrivers($standard, 3.0))->toBe([])
        ->and(array_column($matching->findEligibleDrivers($priority, 3.0), 'user_id'))->toBe([$driver['user']->id]);
});

it('holds only the driver a waiting Gold passenger would be offered next', function () {
    $next = onlineDriver($this);
    $other = onlineDriver($this);
    rideInStatus($this, RideStatus::Searching, ['pickup_lat' => 6.5244, 'pickup_lng' => 3.3792, 'pricing_snapshot' => ['priority_matching' => true]]);
    $standard = rideInStatus($this, RideStatus::Searching, ['pickup_lat' => 6.5300, 'pickup_lng' => 3.3800]);
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([
        ['driver_id' => $next['driver']->id, 'distance_km' => 0.6],
        ['driver_id' => $other['driver']->id, 'distance_km' => 1.1],
    ]);
    // Both are within the Gold ride's radius; the first is nearer to its pickup.
    $locations->shouldReceive('getDriverLocation')->andReturnUsing(fn (string $driverId) => [
        'lat' => $driverId === $next['driver']->id ? 6.5250 : 6.5400,
        'lng' => 3.3795, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp,
    ]);

    $candidates = (new DriverMatchingService($locations))->findEligibleDrivers($standard, 3.0);

    expect(array_column($candidates, 'user_id'))->toBe([$other['user']->id]);
});

it('offers a Gold passenger the nearest driver, never a farther one', function () {
    $near = onlineDriver($this);
    $far = onlineDriver($this);
    $priority = rideInStatus($this, RideStatus::Searching, ['pricing_snapshot' => ['priority_matching' => true]]);
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([
        ['driver_id' => $far['driver']->id, 'distance_km' => 2.6],
        ['driver_id' => $near['driver']->id, 'distance_km' => 0.4],
    ]);

    $candidates = (new DriverMatchingService($locations))->findEligibleDrivers($priority, 3.0);

    expect(array_column($candidates, 'user_id'))->toBe([$near['user']->id, $far['user']->id]);
});

it('stops holding a driver once the priority ride is already waiting on another driver\'s answer', function () {
    $driver = onlineDriver($this);
    $other = onlineDriver($this);
    $priority = rideInStatus($this, RideStatus::Searching, ['pricing_snapshot' => ['priority_matching' => true]]);
    $standard = rideInStatus($this, RideStatus::Searching);
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([['driver_id' => $driver['driver']->id, 'distance_km' => 0.8]]);
    $locations->shouldReceive('getDriverLocation')->andReturn(['lat' => (float) $priority->pickup_lat, 'lng' => (float) $priority->pickup_lng, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp]);
    $matching = new DriverMatchingService($locations);
    $matching->setDispatchedDriver($priority, $other['user']->id);

    expect(array_column($matching->findEligibleDrivers($standard, 3.0), 'user_id'))->toBe([$driver['user']->id]);
});

it('gives a Gold passenger\'s search an extra automatic retry wave', function () {
    $matching = app(DriverMatchingService::class);
    $standard = rideInStatus($this, RideStatus::Searching);
    $priority = rideInStatus($this, RideStatus::Searching, ['pricing_snapshot' => ['priority_matching' => true]]);
    foreach ([$standard, $priority] as $ride) {
        $matching->resetForAutoRetry($ride);
    }

    expect($matching->canAutoRetry($standard))->toBeFalse()
        ->and($matching->canAutoRetry($priority))->toBeTrue();
});

it('locks in priority matching for Gold passengers at booking', function () {
    $this->seed(ReferenceDataSeeder::class);
    GamificationProfile::factory()->create(['user_id' => $this->passenger->id, 'current_tier' => 3]);
    PricingConfig::factory()->create(['city_id' => $this->city->id, 'vehicle_class_id' => $this->vehicleClass->id, 'effective_from' => now()->subDay(), 'created_by_admin_id' => $this->admin->id]);
    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $maps->shouldReceive('reverseGeocode')->andReturn(['address' => '123 Test Street, Lagos']);
    $this->app->instance(MapsGateway::class, $maps);

    $this->actingAs($this->passenger)->postJson('/api/v1/rides', [
        'city_id' => $this->city->id, 'vehicle_class_id' => $this->vehicleClass->id,
        'pickup_lat' => 6.5244, 'pickup_lng' => 3.3792, 'pickup_address' => 'Yaba, Lagos',
        'destination_lat' => 6.4541, 'destination_lng' => 3.3947, 'destination_address' => 'Lagos Island',
        'payment_method' => 'cash',
    ])->assertCreated();

    expect(Ride::first()->pricing_snapshot['priority_matching'])->toBeTrue();
});
