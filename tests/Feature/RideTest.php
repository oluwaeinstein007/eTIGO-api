<?php

use App\Contracts\MapsGateway;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\FinalFareCalculationJob;
use App\Models\City;
use App\Models\Driver;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\User;
use App\Models\VehicleClass;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->create([
        'user_id' => $this->driverUser->id,
        'status' => 'approved',
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->admin = User::factory()->admin()->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

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
        'created_by_admin_id' => $this->admin->id,
    ]);

    $mockMaps = Mockery::mock(MapsGateway::class);
    $mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $mockMaps->shouldReceive('reverseGeocode')
        ->andReturn(['address' => '123 Test Street, Lagos']);
    $this->app->instance(MapsGateway::class, $mockMaps);
});

// === RIDE CREATION ===

it('creates a ride and transitions to searching', function () {
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

    $response->assertCreated()
        ->assertJsonStructure([
            'message',
            'ride' => [
                'id', 'status', 'pickup', 'destination',
                'fare_estimate_amount', 'fare_currency', 'payment_method',
                'share_token', 'created_at',
            ],
            'pin_code',
        ])
        ->assertJsonPath('ride.status', 'searching')
        ->assertJsonPath('ride.payment_method', 'cash');

    $rideId = $response->json('ride.id');
    $this->assertDatabaseHas('rides', [
        'id' => $rideId,
        'status' => 'searching',
        'passenger_id' => $this->passenger->id,
    ]);

    expect(RideStateTransition::where('ride_id', $rideId)->count())->toBe(1);
});

it('returns a 4-digit PIN on ride creation', function () {
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

    $pin = $response->json('pin_code');
    expect($pin)->toBeString()->toHaveLength(4)->toMatch('/^\d{4}$/');
});

it('prevents creating a ride with an active ride in progress', function () {
    Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Searching,
    ]);

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

    $response->assertStatus(409);
});

it('rejects ride creation with same pickup and destination', function () {
    $response = $this->withToken($this->passengerToken)
        ->postJson('/api/v1/rides', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'pickup_address' => '123 Test Street',
            'destination_lat' => 6.5244,
            'destination_lng' => 3.3792,
            'destination_address' => '123 Test Street',
            'payment_method' => 'cash',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('destination_lat');
});

it('rejects ride creation with invalid payment method', function () {
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
            'payment_method' => 'bitcoin',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');
});

// === RIDE CANCELLATION ===

it('allows passenger to cancel a searching ride', function () {
    $ride = Ride::factory()->searching()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/cancel", [
            'reason' => 'changed_mind',
        ]);

    $response->assertOk()
        ->assertJsonPath('ride.status', 'cancelled');

    $this->assertDatabaseHas('rides', [
        'id' => $ride->id,
        'status' => 'cancelled',
        'cancelled_by' => $this->passenger->id,
    ]);
});

it('rejects cancellation of completed ride', function () {
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/cancel");

    $response->assertUnprocessable();
});

it('rejects cancellation of in-progress ride', function () {
    $ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/cancel");

    $response->assertUnprocessable();
});

// === DRIVER ARRIVED ===

it('allows driver to mark arrival', function () {
    $ride = Ride::factory()->driverEnRoute()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    $response = $this->withToken($this->driverToken)
        ->postJson("/api/v1/rides/{$ride->id}/driver-arrived");

    $response->assertOk()
        ->assertJsonPath('ride.status', 'driver_arrived');
});

it('rejects arrival by unassigned driver', function () {
    $otherDriver = User::factory()->create(['type' => UserType::Driver]);
    Driver::factory()->create(['user_id' => $otherDriver->id, 'status' => 'approved']);
    $otherToken = $otherDriver->createToken('auth', ['driver'])->plainTextToken;

    $ride = Ride::factory()->driverEnRoute()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    $response = $this->withToken($otherToken)
        ->postJson("/api/v1/rides/{$ride->id}/driver-arrived");

    $response->assertForbidden();
});

// === PIN VERIFICATION ===

it('verifies PIN and starts ride', function () {
    $ride = Ride::factory()->driverArrived()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    $pin = app(\App\Services\RidePinService::class)->generatePin($ride);

    $response = $this->withToken($this->driverToken)
        ->postJson("/api/v1/rides/{$ride->id}/verify-pin", [
            'pin_code' => $pin['pin_code'],
        ]);

    $response->assertOk()
        ->assertJsonPath('verified', true)
        ->assertJsonPath('ride.status', 'in_progress');
});

it('rejects invalid PIN', function () {
    $ride = Ride::factory()->driverArrived()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    app(\App\Services\RidePinService::class)->generatePin($ride);

    $response = $this->withToken($this->driverToken)
        ->postJson("/api/v1/rides/{$ride->id}/verify-pin", [
            'pin_code' => '0000',
        ]);

    $response->assertUnprocessable()
        ->assertJsonPath('verified', false);
});

// === RIDE COMPLETION ===

it('completes a ride and dispatches fare calculation job', function () {
    Queue::fake();

    $ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    $response = $this->withToken($this->driverToken)
        ->postJson("/api/v1/rides/{$ride->id}/complete");

    $response->assertOk()
        ->assertJsonPath('ride.status', 'completed');

    Queue::assertPushed(FinalFareCalculationJob::class, function ($job) use ($ride) {
        return $job->rideId === $ride->id;
    });
});

it('rejects completion of non-in-progress ride', function () {
    $ride = Ride::factory()->driverArrived()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    $response = $this->withToken($this->driverToken)
        ->postJson("/api/v1/rides/{$ride->id}/complete");

    $response->assertUnprocessable();
});

// === RIDE SHOW ===

it('shows ride details for passenger', function () {
    $ride = Ride::factory()->searching()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->getJson("/api/v1/rides/{$ride->id}");

    $response->assertOk()
        ->assertJsonStructure([
            'ride' => [
                'id', 'status', 'pickup', 'destination',
                'fare_estimate_amount', 'fare_currency',
                'state_transitions',
            ],
        ]);
});

it('prevents passenger from viewing another passengers ride', function () {
    $otherPassenger = User::factory()->create(['type' => UserType::Passenger]);

    $ride = Ride::factory()->searching()->create([
        'passenger_id' => $otherPassenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->getJson("/api/v1/rides/{$ride->id}");

    $response->assertForbidden();
});

it('allows admin to view any ride', function () {
    $ride = Ride::factory()->searching()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->adminToken)
        ->getJson("/api/v1/rides/{$ride->id}");

    $response->assertOk();
});

// === RIDE INDEX ===

it('lists rides for passenger', function () {
    Ride::factory()->count(3)->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->getJson('/api/v1/rides');

    $response->assertOk()
        ->assertJsonCount(3, 'rides')
        ->assertJsonStructure([
            'rides',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('filters rides by status', function () {
    Ride::factory()->searching()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);
    Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->withToken($this->passengerToken)
        ->getJson('/api/v1/rides?status=completed');

    $response->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('rides.0.status', 'completed');
});

// === SHARE LINK ===

it('shows ride via share token without auth', function () {
    $ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'driver_id' => $this->driverUser->id,
    ]);

    $response = $this->getJson("/api/v1/rides/{$ride->id}/share/{$ride->share_token}");

    $response->assertOk()
        ->assertJsonStructure([
            'ride' => ['id', 'status', 'pickup', 'destination'],
        ]);
});

it('rejects invalid share token', function () {
    $ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $response = $this->getJson("/api/v1/rides/{$ride->id}/share/invalid-token");

    $response->assertNotFound();
});

it('expires share link after completed ride plus one hour', function () {
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'completed_at' => now()->subHours(2),
    ]);

    $response = $this->getJson("/api/v1/rides/{$ride->id}/share/{$ride->share_token}");

    $response->assertStatus(410);
});

// === STATE MACHINE UNIT ===

it('records state transition audit trail', function () {
    $ride = Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Requested,
    ]);

    $stateMachine = app(\App\Services\RideStateMachine::class);
    $stateMachine->transitionTo($ride, RideStatus::Searching, $this->passenger, 'passenger');

    $transitions = RideStateTransition::where('ride_id', $ride->id)->get();

    expect($transitions)->toHaveCount(1);
    expect($transitions->first()->from_state)->toBe(RideStatus::Requested);
    expect($transitions->first()->to_state)->toBe(RideStatus::Searching);
    expect($transitions->first()->triggered_by_type)->toBe('passenger');
});

it('rejects invalid state transitions', function () {
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    $stateMachine = app(\App\Services\RideStateMachine::class);

    expect(fn () => $stateMachine->transitionTo($ride, RideStatus::InProgress))
        ->toThrow(\InvalidArgumentException::class);
});

it('prevents skipping states in the ride lifecycle', function () {
    $ride = Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Requested,
    ]);

    $stateMachine = app(\App\Services\RideStateMachine::class);

    expect(fn () => $stateMachine->transitionTo($ride, RideStatus::InProgress))
        ->toThrow(\InvalidArgumentException::class);
});
