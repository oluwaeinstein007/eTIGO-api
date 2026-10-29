<?php

use App\Contracts\MapsGateway;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\DispatchRideRequestJob;
use App\Jobs\ProcessScheduledRidesJob;
use App\Jobs\ScheduledRideReminderJob;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\DriverMatchingService;
use App\Services\RideService;
use App\Services\ScheduledRideService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // These tests book in cash, which is off by default.
    AppSetting::setValue('wallet.cash_payments_enabled', 1, 'wallet');

    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));

    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->passenger = User::factory()->create(['type' => UserType::Passenger, 'first_name' => 'Ade']);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;
    $this->admin = User::factory()->admin()->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN', 'timezone' => 'Africa/Lagos']);
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

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $maps->shouldReceive('reverseGeocode')->andReturn(['address' => '123 Test Street, Lagos']);
    $this->app->instance(MapsGateway::class, $maps);

    // Reserved drivers report a fresh location unless a test says otherwise.
    $this->locationFresh = true;
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturnUsing(fn () => $this->locationFresh
        ? ['lat' => 6.52, 'lng' => 3.38, 'heading' => 0, 'speed' => 0, 'timestamp' => now()->timestamp]
        : null);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([]);
    $this->app->instance(DriverLocationService::class, $locations);

    $this->makeDriver = function (array $driverAttributes = []) {
        $user = User::factory()->create(['type' => UserType::Driver, 'first_name' => 'Bayo']);
        $driver = Driver::factory()->approved()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'is_online' => true,
            ...$driverAttributes,
        ]);
        Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => $this->vehicleClass->id]);

        return [$user, $user->createToken('auth', ['driver'])->plainTextToken];
    };
    [$this->driverUser, $this->driverToken] = ($this->makeDriver)();

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->book = function (array $overrides = []) {
        return ($this->as)($this->passengerToken)->postJson('/api/v1/rides', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'pickup_address' => '123 Test Street',
            'destination_lat' => 6.4541,
            'destination_lng' => 3.3947,
            'destination_address' => '456 Dest Street',
            'payment_method' => 'cash',
            'scheduled_pickup_at' => now()->addHours(3)->toIso8601String(),
            ...$overrides,
        ])->assertCreated()->json('ride.id');
    };

    $this->reserve = fn (string $rideId, ?string $token = null) => ($this->as)($token ?? $this->driverToken)
        ->postJson("/api/v1/driver/scheduled-rides/{$rideId}/reserve");

    $this->dispatchNow = fn (string $rideId) => app(ScheduledRideService::class)
        ->dispatch(ScheduledRide::where('ride_id', $rideId)->value('id'));
});

// === DRIVER RESERVATIONS ===

it('lists bookings a driver can reserve and lets them reserve one', function () {
    $rideId = ($this->book)();

    // Another class, another city, and one inside the dispatch window are not offered.
    $otherClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($otherClass->id, ['is_active' => true]);
    PricingConfig::factory()->create(['city_id' => $this->city->id, 'vehicle_class_id' => $otherClass->id, 'effective_from' => now()->subDay(), 'created_by_admin_id' => $this->admin->id]);
    ($this->book)(['vehicle_class_id' => $otherClass->id, 'scheduled_pickup_at' => now()->addHours(6)->toIso8601String()]);

    ($this->as)($this->driverToken)->getJson('/api/v1/driver/scheduled-rides/available')
        ->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('rides.0.id', $rideId)
        ->assertJsonPath('rides.0.reserved_by_me', false)
        ->assertJsonMissingPath('rides.0.passenger.phone');

    ($this->reserve)($rideId)
        ->assertOk()
        ->assertJsonPath('ride.reserved_by_me', true);

    expect(ScheduledRide::where('ride_id', $rideId)->value('reserved_driver_id'))->toBe($this->driverUser->id)
        ->and(Notification::where('user_id', $this->passenger->id)->where('type', 'scheduled_ride_reserved')->exists())->toBeTrue();

    ($this->as)($this->driverToken)->getJson('/api/v1/driver/scheduled-rides')
        ->assertOk()
        ->assertJsonCount(1, 'rides');
    ($this->as)($this->driverToken)->getJson('/api/v1/driver/scheduled-rides/available')
        ->assertJsonCount(0, 'rides');

    ($this->as)($this->passengerToken)->getJson("/api/v1/rides/{$rideId}")
        ->assertJsonPath('ride.scheduled.reserved_driver.first_name', 'Bayo')
        ->assertJsonMissingPath('ride.scheduled.reserved_driver.last_name')
        ->assertJsonPath('ride.scheduled.reserved_by_operations', false);
});

it('refuses reservations that break the rules', function () {
    $rideId = ($this->book)();
    [$otherUser, $otherToken] = ($this->makeDriver)();

    ($this->reserve)($rideId)->assertOk();
    ($this->reserve)($rideId, $otherToken)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ride' => 'Another driver has already reserved this ride.']);

    // Too close to another of the driver's reservations.
    $near = ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->addMinutes(40)->toIso8601String()]);
    ($this->reserve)($near)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ride' => 'within 60 minutes']);

    // Inside the dispatch window.
    $soon = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()]);
    $this->travel(26)->minutes();
    ($this->reserve)($soon, $otherToken)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ride' => 'can no longer be reserved']);

    AppSetting::setValue('scheduled_rides.driver_reservations_enabled', 'false', 'scheduled_rides');
    ($this->reserve)($near, $otherToken)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ride' => 'not available']);
});

it('lets a driver release a reservation and tells the passenger', function () {
    $rideId = ($this->book)();
    ($this->reserve)($rideId)->assertOk();

    ($this->as)($this->driverToken)->postJson("/api/v1/driver/scheduled-rides/{$rideId}/release")
        ->assertOk();

    $scheduled = ScheduledRide::where('ride_id', $rideId)->first();
    expect($scheduled->reserved_driver_id)->toBeNull()
        ->and($scheduled->reservation_released_reason)->toBe('driver_released')
        ->and(Notification::where('user_id', $this->passenger->id)->where('type', 'scheduled_ride_reservation_released')->exists())->toBeTrue();
});

it('releases the reservation when the passenger moves the pickup', function () {
    $rideId = ($this->book)();
    ($this->reserve)($rideId)->assertOk();

    ($this->as)($this->passengerToken)->putJson("/api/v1/rides/{$rideId}/scheduled", ['payment_method' => 'card'])->assertOk();
    expect(ScheduledRide::where('ride_id', $rideId)->value('reserved_driver_id'))->toBe($this->driverUser->id);

    ($this->as)($this->passengerToken)->putJson("/api/v1/rides/{$rideId}/scheduled", [
        'scheduled_pickup_at' => now()->addHours(4)->toIso8601String(),
    ])->assertOk()->assertJsonPath('ride.scheduled.reserved_driver', null);

    expect(ScheduledRide::where('ride_id', $rideId)->value('reservation_released_reason'))->toBe('pickup_changed')
        ->and(Notification::where('user_id', $this->driverUser->id)->where('type', 'scheduled_ride_reservation_released')->exists())->toBeTrue();
});

// === DISPATCH TO THE RESERVED DRIVER ===

it('gives the ride straight to the reserved driver at dispatch', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()]);
    ($this->reserve)($rideId)->assertOk();
    $this->travel(26)->minutes();

    expect(($this->dispatchNow)($rideId))->toBe('assigned');

    $ride = Ride::find($rideId);
    expect($ride->status)->toBe(RideStatus::DriverEnRoute)
        ->and($ride->driver_id)->toBe($this->driverUser->id)
        ->and(Notification::where('user_id', $this->passenger->id)->where('type', 'scheduled_ride_dispatched')->exists())->toBeFalse()
        ->and(Notification::where('user_id', $this->passenger->id)->where('type', 'ride_matched')->exists())->toBeTrue();

    Queue::assertNotPushed(DispatchRideRequestJob::class);
});

it('falls back to normal matching when the reserved driver is offline or busy', function () {
    $offline = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()]);
    ($this->reserve)($offline)->assertOk();
    [$busyUser, $busyToken] = ($this->makeDriver)();
    $busy = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(110)->toIso8601String()]);
    ($this->reserve)($busy, $busyToken)->assertOk();

    $this->travel(26)->minutes();
    $this->locationFresh = false;
    expect(($this->dispatchNow)($offline))->toBe('dispatched')
        ->and(Ride::find($offline)->status)->toBe(RideStatus::Searching)
        ->and(ScheduledRide::where('ride_id', $offline)->value('reservation_released_reason'))->toBe('driver_unavailable');
    Queue::assertPushed(DispatchRideRequestJob::class, fn ($job) => $job->rideId === $offline);

    // The passenger is done with the first ride before the second one starts.
    Ride::whereKey($offline)->update(['status' => RideStatus::Cancelled]);
    $this->locationFresh = true;
    Ride::factory()->create([
        'driver_id' => $busyUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::InProgress,
    ]);
    $this->travel(70)->minutes();
    expect(($this->dispatchNow)($busy))->toBe('dispatched')
        ->and(Ride::find($busy)->driver_id)->toBeNull();
});

it('keeps a reserved driver out of on-demand offers shortly before their pickup', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(50)->toIso8601String()]);
    ($this->reserve)($rideId)->assertOk();

    $onDemand = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Searching,
    ]);

    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([
        ['driver_id' => $this->driverUser->driver->id, 'distance_km' => 1.0],
    ]);
    $this->app->instance(DriverLocationService::class, $locations);

    expect(app(DriverMatchingService::class)->findEligibleDrivers($onDemand, 3.0))->toBe([]);

    AppSetting::setValue('scheduled_rides.driver_reservation_buffer_minutes', 30, 'scheduled_rides');
    expect(app(DriverMatchingService::class)->findEligibleDrivers($onDemand, 3.0))->toHaveCount(1);
});

it('reminds the reserved driver before pickup', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addHours(2)->toIso8601String()]);
    ($this->reserve)($rideId)->assertOk();

    $this->travel(61)->minutes();
    (new ProcessScheduledRidesJob)->handle();
    Queue::assertPushed(ScheduledRideReminderJob::class);

    $id = ScheduledRide::where('ride_id', $rideId)->value('id');
    expect(app(ScheduledRideService::class)->sendDriverReminder($id))->toBeTrue()
        ->and(app(ScheduledRideService::class)->sendDriverReminder($id))->toBeFalse();

    expect(Notification::where('user_id', $this->driverUser->id)->where('type', 'scheduled_ride_reminder')->value('body'))
        ->toContain('Be online by 10:45 AM');
});

// === ADMIN ASSIGNMENT ===

it('lets operations assign, move and remove a driver before dispatch', function () {
    $rideId = ($this->book)();
    [$secondUser] = ($this->makeDriver)();

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/scheduled-rides/{$rideId}/assign", ['driver_id' => $this->driverUser->id])
        ->assertOk()
        ->assertJsonPath('ride.scheduled.reserved_by_operations', true);
    expect(Notification::where('user_id', $this->driverUser->id)->where('type', 'scheduled_ride_reserved')->exists())->toBeTrue();

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/scheduled-rides/{$rideId}/assign", ['driver_id' => $secondUser->id])
        ->assertOk()
        ->assertJsonPath('ride.scheduled.reserved_driver.id', $secondUser->id);
    expect(Notification::where('user_id', $this->driverUser->id)->where('type', 'scheduled_ride_reservation_released')->exists())->toBeTrue();

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/scheduled-rides?reserved=1')
        ->assertJsonCount(1, 'rides');

    ($this->as)($this->adminToken)->deleteJson("/api/v1/admin/scheduled-rides/{$rideId}/assign")
        ->assertOk()
        ->assertJsonPath('ride.scheduled.reserved_driver', null);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/scheduled-rides/{$rideId}/assign", ['driver_id' => $this->passenger->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('driver_id');
});

it('searches the scheduled list by reference, names, phones and address', function () {
    $this->passenger->update(['first_name' => 'Ade', 'last_name' => 'Bello', 'phone' => '+2348031234567']);
    $this->driverUser->update(['last_name' => 'Akin', 'phone' => '+2347011112222']);
    $other = User::factory()->create(['type' => UserType::Passenger, 'first_name' => 'Chioma', 'last_name' => 'Eze', 'phone' => '+2348099990000']);

    $own = ($this->book)();
    $forRider = ($this->book)(['scheduled_pickup_at' => now()->addHours(5)->toIso8601String(), 'pickup_address' => '7 Marina Road']);
    Ride::whereKey($forRider)->update([
        'passenger_id' => $other->id,
        'is_third_party' => true,
        'rider_name' => 'Ada Okafor',
        'rider_phone' => '+2348020000002',
    ]);
    ($this->reserve)($forRider)->assertOk();

    $found = fn (string $search) => collect(($this->as)($this->adminToken)
        ->getJson('/api/v1/admin/scheduled-rides?search='.urlencode($search))
        ->assertOk()
        ->json('rides'))->pluck('id')->all();

    expect($found('Ade Bello'))->toBe([$own])
        ->and($found('bello'))->toBe([$own])
        ->and($found('0803 123 4567'))->toBe([$own])
        ->and($found('+234 803-123'))->toBe([$own])
        ->and($found(Ride::find($forRider)->reference_number))->toBe([$forRider])
        ->and($found('Chioma Eze'))->toBe([$forRider])
        ->and($found('Ada Okafor'))->toBe([$forRider])
        ->and($found('08020000002'))->toBe([$forRider])
        ->and($found('Bayo Akin'))->toBe([$forRider])
        ->and($found('07011112222'))->toBe([$forRider])
        ->and($found('marina'))->toBe([$forRider])
        ->and($found('  '))->toBe([$own, $forRider])
        ->and($found('100%'))->toBe([])
        ->and($found('Zainab'))->toBe([]);

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/scheduled-rides?search=bello&reserved=1')
        ->assertOk()
        ->assertJsonCount(0, 'rides');

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/scheduled-rides?search='.str_repeat('a', 101))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('names the reserved driver in full for operations', function () {
    $this->driverUser->update(['last_name' => 'Akin']);
    $rideId = ($this->book)();
    ($this->reserve)($rideId)->assertOk();

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/scheduled-rides')
        ->assertOk()
        ->assertJsonPath('rides.0.scheduled.reserved_driver.first_name', 'Bayo')
        ->assertJsonPath('rides.0.scheduled.reserved_driver.last_name', 'Akin');

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/scheduled-rides/{$rideId}/assign", ['driver_id' => $this->driverUser->id])
        ->assertOk()
        ->assertJsonPath('ride.scheduled.reserved_driver.last_name', 'Akin');
});

it('keeps bookings at the same pickup time in a fixed order across pages', function () {
    $first = ($this->book)();
    $second = ($this->book)(['scheduled_pickup_at' => now()->addHours(5)->toIso8601String()]);
    ScheduledRide::whereIn('ride_id', [$first, $second])->update(['scheduled_pickup_at' => now()->addHours(4)]);

    $page = fn (int $n) => ($this->as)($this->adminToken)
        ->getJson("/api/v1/admin/scheduled-rides?per_page=1&page={$n}")
        ->assertOk()
        ->json('rides.0.id');

    expect([$page(1), $page(2)])->toBe(collect([$first, $second])->sort()->values()->all());
});

// === FEES ===

it('adds the reservation fee to the quote and the final fare', function () {
    AppSetting::setValue('scheduled_rides.reservation_fee', 250, 'scheduled_rides');

    $response = ($this->as)($this->passengerToken)->postJson('/api/v1/rides/estimate', [
        'city_id' => $this->city->id,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'scheduled_pickup_at' => now()->addHours(3)->toIso8601String(),
    ])->assertOk();
    expect((float) $response->json('estimates.0.fare_estimate'))->toBe(3575.0)
        ->and($response->json('estimates.0.reservation_fee'))->toEqual(250);

    $rideId = ($this->book)();
    $ride = Ride::find($rideId);
    expect((float) $ride->fare_estimate_amount)->toBe(3575.0)
        ->and($ride->pricing_snapshot['reservation_fee'])->toEqual(250);

    $ride->update(['status' => RideStatus::Completed]);
    expect(app(RideService::class)->calculateFinalFare($ride->fresh())['final_fare'])->toBe(3575.0);

    // On-demand rides never carry it.
    $onDemand = ($this->book)(['scheduled_pickup_at' => null]);
    expect((float) Ride::find($onDemand)->fare_estimate_amount)->toBe(3325.0);
});
