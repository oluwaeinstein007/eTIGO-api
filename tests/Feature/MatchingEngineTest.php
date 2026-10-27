<?php

use App\Contracts\MapsGateway;
use App\Contracts\PushNotificationGateway;
use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Events\RideRequestDispatched;
use App\Jobs\DispatchRideRequestJob;
use App\Jobs\MatchingTimeoutJob;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\Driver;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\DriverMatchingService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // These tests book in cash, which is off by default.
    AppSetting::setValue('wallet.cash_payments_enabled', 1, 'wallet');

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

it('takes an accept up to the offer deadline plus the grace for taps in transit', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);
    $this->freezeSecond();
    app(DriverMatchingService::class)->setDispatchedDriver($ride, $d['user']->id);

    $this->travel(config('matching.driver_response_timeout') + DriverMatchingService::ACCEPT_GRACE_SECONDS)->seconds();

    $this->withToken($d['token'])->postJson("/api/v1/rides/{$ride->id}/accept")->assertOk();
});

it('refuses an accept once the offer has expired, even before the timeout job runs', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);
    $this->freezeSecond();
    app(DriverMatchingService::class)->setDispatchedDriver($ride, $d['user']->id);

    $this->travel(config('matching.driver_response_timeout') + DriverMatchingService::ACCEPT_GRACE_SECONDS + 1)->seconds();

    $this->withToken($d['token'])->getJson('/api/v1/driver/ride-offer')->assertExactJson(['offer' => null]);
    $this->withToken($d['token'])->postJson("/api/v1/rides/{$ride->id}/accept")
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'This ride offer has expired.']);

    expect($ride->fresh()->status)->toBe(RideStatus::Searching)
        ->and($ride->fresh()->driver_id)->toBeNull();
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

it('rejects rejection from a driver not dispatched to the ride', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);

    $matchingService = app(DriverMatchingService::class);
    $matchingService->setDispatchedDriver($ride, '00000000-0000-0000-0000-000000000999');

    $response = $this->withToken($d['token'])
        ->postJson("/api/v1/rides/{$ride->id}/reject");

    $response->assertForbidden();
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

it('rejects admin assign for a ride that already has a driver', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = $admin->createToken('auth', ['admin'])->plainTextToken;

    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);
    $ride->update(['status' => RideStatus::DriverEnRoute]);

    $response = $this->withToken($adminToken)
        ->postJson("/api/v1/admin/rides/{$ride->id}/assign", [
            'driver_id' => $d['user']->id,
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error_code', 'RIDE_NOT_ASSIGNABLE')
        ->assertJsonPath('current_status', 'driver_en_route');
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

it('allows admin to assign a driver to a ride in Requested status', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = $admin->createToken('auth', ['admin'])->plainTextToken;

    $d = createOnlineDriver($this);
    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'status' => RideStatus::Requested,
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

// === WEBSOCKET EVENT ===

it('broadcasts RideRequestDispatched on the correct private channel', function () {
    $driverUuid = '00000000-0000-0000-0000-000000000042';
    $event = new RideRequestDispatched(
        driverUserId: $driverUuid,
        rideId: 'ride-uuid-123',
        pickupAddress: '123 Pickup St',
        destinationAddress: '456 Dest Ave',
        pickupLat: 6.5244,
        pickupLng: 3.3792,
        destinationLat: 6.4541,
        destinationLng: 3.3947,
        fareEstimate: 3650.0,
        currency: 'NGN',
        vehicleClassName: 'Economy',
        responseTimeoutSeconds: 20,
        paymentMethod: 'card',
        passengerName: 'Christian',
        passengerRating: 4.9,
        distanceKm: 12.6,
        durationMinutes: 28,
    );

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe("private-driver.{$driverUuid}");

    expect($event->broadcastAs())->toBe('ride.request.dispatched');

    $payload = $event->broadcastWith();
    expect($payload)->toHaveKeys([
        'ride_id', 'pickup_address', 'destination_address',
        'pickup_lat', 'pickup_lng', 'destination_lat', 'destination_lng',
        'fare_estimate', 'currency', 'vehicle_class', 'response_timeout_seconds',
        'payment_method', 'passenger_name', 'passenger_rating', 'distance_km',
        'duration_minutes',
    ]);
    expect($payload['ride_id'])->toBe('ride-uuid-123');
    expect($payload['response_timeout_seconds'])->toBe(20);
    expect($payload['payment_method'])->toBe('card');
    expect($payload['passenger_name'])->toBe('Christian');
    expect($payload['passenger_rating'])->toBe(4.9);
    expect($payload['distance_km'])->toBe(12.6);
    expect($payload['duration_minutes'])->toBe(28.0);
});

// === MATCHING SERVICE UNIT ===

it('tracks rejected and dispatched driver state correctly', function () {
    $ride = createSearchingRide($this);
    $service = app(DriverMatchingService::class);

    $driverUuid = '00000000-0000-0000-0000-000000000042';
    $service->setDispatchedDriver($ride, $driverUuid);
    expect($service->getDispatchedDriverId($ride))->toBe($driverUuid);

    $service->markDriverRejected($ride, $driverUuid);
    expect($service->getRejectedDriverIds($ride))->toContain($driverUuid);

    $service->clearDispatchedDriver($ride);
    expect($service->getDispatchedDriverId($ride))->toBeNull();

    $service->cleanupRideCache($ride);
    expect($service->getRejectedDriverIds($ride))->toBeEmpty();
});

it('ignores stale non-UUID driver locations while matching a ride', function () {
    $driver = createOnlineDriver($this)['driver'];
    $ride = createSearchingRide($this);

    $locationService = Mockery::mock(DriverLocationService::class);
    $locationService->shouldReceive('findNearbyDrivers')
        ->once()
        ->andReturn([
            ['driver_id' => 'driver-1', 'distance_km' => 1.0],
            ['driver_id' => $driver->id, 'distance_km' => 2.0],
        ]);

    $matchingService = new DriverMatchingService($locationService);

    $eligibleDrivers = $matchingService->findEligibleDrivers($ride, 15.0);

    expect($eligibleDrivers)->toHaveCount(1)
        ->and($eligibleDrivers[0]['driver_id'])->toBe($driver->id);
});

it('expands search radius using dedicated counter across 3 tiers', function () {
    $ride = createSearchingRide($this);
    $service = app(DriverMatchingService::class);

    $initialRadius = $service->calculateCurrentRadius($ride);
    expect($initialRadius)->toBe(3.0);
    expect($service->hasReachedMaxRadius($ride))->toBeFalse();

    $service->expandRadius($ride);
    expect($service->calculateCurrentRadius($ride))->toBe(7.0);
    expect($service->hasReachedMaxRadius($ride))->toBeFalse();

    $service->expandRadius($ride);
    expect($service->calculateCurrentRadius($ride))->toBe(15.0);
    expect($service->hasReachedMaxRadius($ride))->toBeTrue();
});

it('supports auto-retry cycle and resets for next wave', function () {
    $ride = createSearchingRide($this);
    $service = app(DriverMatchingService::class);

    expect($service->getAutoRetryCount($ride))->toBe(0);
    expect($service->canAutoRetry($ride))->toBeTrue();

    $service->expandRadius($ride);
    $service->expandRadius($ride);
    expect($service->hasReachedMaxRadius($ride))->toBeTrue();

    $service->resetForAutoRetry($ride);
    expect($service->getAutoRetryCount($ride))->toBe(1);
    expect($service->calculateCurrentRadius($ride))->toBe(3.0);
    expect($service->hasReachedMaxRadius($ride))->toBeFalse();
    expect($service->canAutoRetry($ride))->toBeFalse();
});

// === CONFIG ===

it('has sensible matching config defaults', function () {
    expect(config('matching.initial_radius_km'))->toBe(3.0);
    expect(config('matching.radius_tiers_km'))->toBe([3.0, 7.0, 15.0]);
    expect(config('matching.max_auto_retries'))->toBe(1);
    expect(config('matching.driver_response_timeout'))->toBe(20);
    expect(config('matching.matching_timeout'))->toBe(300);
});

it('never offers rides to demo drivers automatically', function () {
    $realDriver = createOnlineDriver($this)['driver'];
    $demo = createOnlineDriver($this);
    $demo['user']->update(['email' => 'driver.test@ops-demo.etigo.com']);
    $ride = createSearchingRide($this);

    $locationService = Mockery::mock(DriverLocationService::class);
    $locationService->shouldReceive('findNearbyDrivers')
        ->once()
        ->andReturn([
            ['driver_id' => $demo['driver']->id, 'distance_km' => 0.5],
            ['driver_id' => $realDriver->id, 'distance_km' => 2.0],
        ]);

    $eligibleDrivers = (new DriverMatchingService($locationService))->findEligibleDrivers($ride, 15.0);

    expect(array_column($eligibleDrivers, 'driver_id'))->toBe([$realDriver->id]);
});

it('includes full ride request payload in push notification when dispatching a ride', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);
    $ride->update([
        'pricing_snapshot' => [
            'distance_km' => 11.4,
            'duration_minutes' => 24.0,
        ],
    ]);

    $locationService = Mockery::mock(DriverLocationService::class);
    $locationService->shouldReceive('findNearbyDrivers')
        ->once()
        ->andReturn([
            ['driver_id' => $d['driver']->id, 'distance_km' => 1.2],
        ]);

    $capturedUserId = null;
    $capturedPayload = null;
    $pushGateway = Mockery::mock(PushNotificationGateway::class);
    $pushGateway->shouldReceive('sendToUser')
        ->once()
        ->withArgs(function (string $userId, array $payload) use (&$capturedUserId, &$capturedPayload) {
            $capturedUserId = $userId;
            $capturedPayload = $payload;

            return true;
        });

    $job = new DispatchRideRequestJob($ride->id);
    $job->handle(new DriverMatchingService($locationService), $pushGateway);

    expect($capturedUserId)->toBe($d['user']->id);
    expect($capturedPayload['data'])->toMatchArray([
        'type' => 'ride_request',
        'ride_id' => (string) $ride->id,
        'pickup_address' => '123 Test Street',
        'destination_address' => '456 Dest Street',
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'fare_estimate' => 3650.0,
        'currency' => 'NGN',
        'response_timeout_seconds' => config('matching.driver_response_timeout', 30),
        'payment_method' => 'cash',
        'distance_km' => 11.4,
        'duration_minutes' => 24.0,
    ]);
    expect($capturedPayload['data']['dispatched_at'])->toBeString()->not->toBeEmpty();
});

// === PENDING OFFER (GET /driver/ride-offer) ===

it('returns the offer a driver has not answered, with the time left', function () {
    $this->freezeSecond();
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);
    $dispatchedAt = app(DriverMatchingService::class)->setDispatchedDriver($ride, $d['user']->id);

    $this->travel(5)->seconds();

    $response = $this->withToken($d['token'])->getJson('/api/v1/driver/ride-offer');

    $timeout = config('matching.driver_response_timeout');
    $response->assertOk()
        ->assertJsonPath('offer.ride_id', $ride->id)
        ->assertJsonPath('offer.pickup_address', '123 Test Street')
        ->assertJsonPath('offer.response_timeout_seconds', $timeout)
        ->assertJsonPath('offer.is_third_party', false)
        ->assertJsonPath('offer.dispatched_at', $dispatchedAt->toIso8601String())
        ->assertJsonPath('offer.expires_at', $dispatchedAt->copy()->addSeconds($timeout)->toIso8601String())
        ->assertJsonPath('offer.seconds_remaining', $timeout - 5);
    expect(array_keys($response->json('offer')))->toEqualCanonicalizing([
        ...array_keys(RideRequestDispatched::fromRide($d['user']->id, $ride, $dispatchedAt)->broadcastWith()),
        'expires_at', 'seconds_remaining',
    ]);
});

it('gives the endpoint and the push the same offer', function () {
    $d = createOnlineDriver($this);
    $ride = createSearchingRide($this);

    $locationService = Mockery::mock(DriverLocationService::class);
    $locationService->shouldReceive('findNearbyDrivers')
        ->andReturn([['driver_id' => $d['driver']->id, 'distance_km' => 1.2]]);

    $pushData = null;
    $pushGateway = Mockery::mock(PushNotificationGateway::class);
    $pushGateway->shouldReceive('sendToUser')->once()
        ->withArgs(function (string $userId, array $payload) use (&$pushData) {
            $pushData = $payload['data'];

            return true;
        });

    $matchingService = new DriverMatchingService($locationService);
    $this->app->instance(DriverMatchingService::class, $matchingService);
    (new DispatchRideRequestJob($ride->id))->handle($matchingService, $pushGateway);

    $offer = $this->withToken($d['token'])->getJson('/api/v1/driver/ride-offer')->assertOk()->json('offer');

    expect($offer['ride_id'])->toBe($ride->id)
        ->and($offer['dispatched_at'])->toBe($pushData['dispatched_at'])
        ->and(array_diff_key($pushData, ['type' => 1, 'response_timeout' => 1]))
        ->toEqual(array_filter(
            array_diff_key($offer, ['expires_at' => 1, 'seconds_remaining' => 1]),
            static fn (mixed $value): bool => $value !== null,
        ));
});

it('returns no offer once it has run out, gone to another driver or been taken', function (string $case) {
    $d = createOnlineDriver($this);
    $other = createOnlineDriver($this);
    $ride = createSearchingRide($this);
    $matching = app(DriverMatchingService::class);
    $matching->setDispatchedDriver($ride, $d['user']->id);

    match ($case) {
        'expired' => $this->travel(config('matching.driver_response_timeout'))->seconds(),
        'timed out' => $matching->clearDispatchedDriver($ride),
        'cancelled' => $ride->update(['status' => RideStatus::Cancelled]),
        'other driver' => $d = $other,
    };

    $this->withToken($d['token'])->getJson('/api/v1/driver/ride-offer')
        ->assertOk()
        ->assertExactJson(['offer' => null]);
})->with(['expired', 'timed out', 'cancelled', 'other driver']);

it('returns no offer to a driver who has none', function () {
    $d = createOnlineDriver($this);

    $this->withToken($d['token'])->getJson('/api/v1/driver/ride-offer')
        ->assertOk()
        ->assertExactJson(['offer' => null]);
});

it('does not let passengers read ride offers', function () {
    $this->withToken($this->passengerToken)->getJson('/api/v1/driver/ride-offer')->assertForbidden();
});
