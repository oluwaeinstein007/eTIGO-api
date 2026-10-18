<?php

use App\Contracts\MapsGateway;
use App\Enums\AdminRole;
use App\Enums\DriverShiftStatus;
use App\Enums\FleetAgreementStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Enums\VehicleOwnershipType;
use App\Jobs\ProcessDriverShiftsJob;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\DriverAvailabilityPlan;
use App\Models\DriverShift;
use App\Models\FleetAgreement;
use App\Models\Notification;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Notifications\OperationsAlertNotification;
use App\Services\DriverLocationService;
use App\Services\DriverMatchingService;
use App\Services\DriverShiftService;
use App\Services\OperationsAlertService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    // Monday 19 Oct 2026, 08:00 UTC (09:00 in Lagos).
    $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));

    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;
    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN', 'timezone' => 'Africa/Lagos']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $maps->shouldReceive('reverseGeocode')->andReturn(['address' => '123 Test Street, Lagos']);
    $this->app->instance(MapsGateway::class, $maps);

    $this->nearby = [];
    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturn(['lat' => 6.52, 'lng' => 3.38, 'heading' => 0, 'speed' => 0, 'timestamp' => now()->timestamp]);
    $locations->shouldReceive('findNearbyDrivers')->andReturnUsing(fn () => $this->nearby);
    $locations->shouldReceive('removeDriver')->andReturnNull();
    $this->app->instance(DriverLocationService::class, $locations);

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    // A fleet driver with no car of their own.
    $this->makeFleetDriver = function (string $name = 'Bayo') {
        $user = User::factory()->create(['type' => UserType::Driver, 'first_name' => $name]);
        $driver = Driver::factory()->approved()->create([
            'user_id' => $user->id,
            'city_id' => $this->city->id,
            'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
        ]);

        return [$driver, $user->createToken('auth', ['driver'])->plainTextToken];
    };

    // A shared fleet car in shift mode, not held by anyone.
    $this->car = Vehicle::factory()->fleet()->create([
        'driver_id' => null,
        'shift_mode' => true,
        'vehicle_class_id' => $this->vehicleClass->id,
        'plate_number' => 'LAG-100AA',
    ]);

    [$this->day, $this->dayToken] = ($this->makeFleetDriver)('Bayo');
    [$this->night, $this->nightToken] = ($this->makeFleetDriver)('Kemi');

    $this->shift = fn (Driver $driver, string $start, string $end, array $extra = []) => DriverShift::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $this->car->id,
        'starts_at' => Carbon::parse($start, 'UTC'),
        'ends_at' => Carbon::parse($end, 'UTC'),
        ...$extra,
    ]);
});

// === SHIFT MODE ===

it('turns shift mode on only for fleet cars without a hire-purchase agreement, and off only without upcoming shifts', function () {
    $own = Vehicle::factory()->create();
    ($this->as)($this->adminToken)->putJson("/api/v1/admin/fleet-vehicles/{$own->id}/shift-mode", ['shift_mode' => true])
        ->assertStatus(422);

    $hirePurchase = Vehicle::factory()->fleet()->create();
    FleetAgreement::factory()->create(['vehicle_id' => $hirePurchase->id, 'driver_id' => $hirePurchase->driver_id, 'status' => FleetAgreementStatus::Active]);
    ($this->as)($this->adminToken)->putJson("/api/v1/admin/fleet-vehicles/{$hirePurchase->id}/shift-mode", ['shift_mode' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('shift_mode');

    $fleet = Vehicle::factory()->fleet()->create(['driver_id' => null]);
    ($this->as)($this->adminToken)->putJson("/api/v1/admin/fleet-vehicles/{$fleet->id}/shift-mode", ['shift_mode' => true])
        ->assertOk()
        ->assertJsonPath('vehicle.shift_mode', true);

    ($this->shift)($this->day, '2026-10-20 05:00', '2026-10-20 13:00');
    ($this->as)($this->adminToken)->putJson("/api/v1/admin/fleet-vehicles/{$this->car->id}/shift-mode", ['shift_mode' => false])
        ->assertStatus(422);
});

it('keeps shared shift cars off hire-purchase agreements', function () {
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/fleet-vehicles/{$this->car->id}/assign", [
        'driver_id' => $this->day->id,
        'daily_remittance_target' => 15000,
        'total_vehicle_cost' => 9000000,
        'agreement_start_date' => '2026-10-20',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'This car is shared on shifts. Turn shift mode off before putting it on a hire-purchase agreement.');

    expect($this->car->fresh()->driver_id)->toBeNull();
});

it('is operations only', function () {
    $finance = User::factory()->admin(AdminRole::Finance)->create();
    $token = $finance->createToken('auth', ['admin'])->plainTextToken;

    ($this->as)($token)->getJson('/api/v1/admin/driver-shifts')->assertForbidden();
    ($this->as)($token)->putJson("/api/v1/admin/fleet-vehicles/{$this->car->id}/shift-mode", ['shift_mode' => false])->assertForbidden();
    ($this->as)($this->dayToken)->getJson('/api/v1/admin/driver-shifts')->assertForbidden();
});

// === ASSIGNING SHIFTS ===

it('creates a shift and a daily rota, and tells the driver once', function () {
    ($this->as)($this->adminToken)->postJson('/api/v1/admin/driver-shifts', [
        'driver_id' => $this->day->id,
        'vehicle_id' => $this->car->id,
        'starts_at' => '2026-10-20T06:00:00+01:00',
        'ends_at' => '2026-10-20T14:00:00+01:00',
        'repeat_daily_for' => 4,
    ])->assertCreated()
        ->assertJsonCount(5, 'shifts')
        ->assertJsonPath('shifts.0.status', 'scheduled')
        ->assertJsonPath('shifts.0.starts_at', '2026-10-20T05:00:00+00:00')
        ->assertJsonPath('shifts.0.online_from', '2026-10-20T04:45:00+00:00')
        ->assertJsonPath('shifts.0.offers_stop_at', '2026-10-20T12:45:00+00:00')
        ->assertJsonPath('shifts.4.starts_at', '2026-10-24T05:00:00+00:00');

    expect(DriverShift::where('driver_id', $this->day->id)->count())->toBe(5)
        ->and(Notification::where('user_id', $this->day->user_id)->where('type', 'driver_shift_assigned')->count())->toBe(1);
});

it('supports overnight shifts and refuses overlaps on the car or the driver', function () {
    // 22:00–06:00 Lagos.
    ($this->as)($this->adminToken)->postJson('/api/v1/admin/driver-shifts', [
        'driver_id' => $this->night->id,
        'vehicle_id' => $this->car->id,
        'starts_at' => '2026-10-19T22:00:00+01:00',
        'ends_at' => '2026-10-20T06:00:00+01:00',
    ])->assertCreated();

    // Same car, overlapping the night shift.
    ($this->as)($this->adminToken)->postJson('/api/v1/admin/driver-shifts', [
        'driver_id' => $this->day->id,
        'vehicle_id' => $this->car->id,
        'starts_at' => '2026-10-20T05:00:00+01:00',
        'ends_at' => '2026-10-20T13:00:00+01:00',
    ])->assertStatus(422)->assertJsonValidationErrors('starts_at');

    // Back to back is fine.
    ($this->as)($this->adminToken)->postJson('/api/v1/admin/driver-shifts', [
        'driver_id' => $this->day->id,
        'vehicle_id' => $this->car->id,
        'starts_at' => '2026-10-20T06:00:00+01:00',
        'ends_at' => '2026-10-20T14:00:00+01:00',
    ])->assertCreated();

    // Same driver on another shared car at the same time.
    $otherCar = Vehicle::factory()->fleet()->create(['driver_id' => null, 'shift_mode' => true, 'vehicle_class_id' => $this->vehicleClass->id]);
    ($this->as)($this->adminToken)->postJson('/api/v1/admin/driver-shifts', [
        'driver_id' => $this->day->id,
        'vehicle_id' => $otherCar->id,
        'starts_at' => '2026-10-20T10:00:00+01:00',
        'ends_at' => '2026-10-20T18:00:00+01:00',
    ])->assertStatus(422)->assertJsonValidationErrors('starts_at');
});

it('refuses shifts on cars not in shift mode, for drivers with their own car, and outside the length limits', function () {
    $fleetCar = Vehicle::factory()->fleet()->create(['driver_id' => null]);
    $post = fn (array $data) => ($this->as)($this->adminToken)->postJson('/api/v1/admin/driver-shifts', [
        'driver_id' => $this->day->id,
        'vehicle_id' => $this->car->id,
        'starts_at' => '2026-10-20T06:00:00+01:00',
        'ends_at' => '2026-10-20T14:00:00+01:00',
        ...$data,
    ]);

    $post(['vehicle_id' => $fleetCar->id])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

    $ownCarDriver = Driver::factory()->approved()->create(['city_id' => $this->city->id]);
    Vehicle::factory()->create(['driver_id' => $ownCarDriver->id]);
    $post(['driver_id' => $ownCarDriver->id])->assertStatus(422)->assertJsonValidationErrors('driver_id');

    $post(['ends_at' => '2026-10-20T06:30:00+01:00'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    $post(['ends_at' => '2026-10-20T20:00:00+01:00'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    $post(['starts_at' => '2026-12-01T06:00:00+01:00', 'ends_at' => '2026-12-01T14:00:00+01:00'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
});

it('changes only the end time of a shift in progress, and cancels only shifts not started', function () {
    $active = ($this->shift)($this->day, '2026-10-19 06:00', '2026-10-19 14:00', ['status' => DriverShiftStatus::Active, 'started_at' => now()]);

    ($this->as)($this->adminToken)->putJson("/api/v1/admin/driver-shifts/{$active->id}", ['starts_at' => '2026-10-19T07:00:00Z'])
        ->assertStatus(422);
    ($this->as)($this->adminToken)->putJson("/api/v1/admin/driver-shifts/{$active->id}", ['ends_at' => '2026-10-19T16:00:00Z'])
        ->assertOk()
        ->assertJsonPath('shift.ends_at', '2026-10-19T16:00:00+00:00');
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/driver-shifts/{$active->id}/cancel", ['reason' => 'Car needed'])
        ->assertStatus(422);

    $later = ($this->shift)($this->night, '2026-10-20 06:00', '2026-10-20 14:00');
    ($this->as)($this->adminToken)->postJson("/api/v1/admin/driver-shifts/{$later->id}/cancel", ['reason' => 'Car in the workshop'])
        ->assertOk()
        ->assertJsonPath('shift.status', 'cancelled');

    expect(Notification::where('user_id', $this->night->user_id)->where('type', 'driver_shift_cancelled')->exists())->toBeTrue();
});

it('gives the shift to another driver and tells both', function () {
    $shift = ($this->shift)($this->day, '2026-10-20 06:00', '2026-10-20 14:00');

    ($this->as)($this->adminToken)->putJson("/api/v1/admin/driver-shifts/{$shift->id}", ['driver_id' => $this->night->id])
        ->assertOk();

    expect($shift->fresh()->driver_id)->toBe($this->night->id)
        ->and(Notification::where('user_id', $this->day->user_id)->where('type', 'driver_shift_cancelled')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->night->user_id)->where('type', 'driver_shift_assigned')->exists())->toBeTrue();
});

// === GOING ONLINE ===

it('lets a shift driver go online only from 15 minutes before their shift, and hands the car over', function () {
    ($this->shift)($this->day, '2026-10-19 08:30', '2026-10-19 16:30');

    // 08:00: 30 minutes early.
    ($this->as)($this->dayToken)->postJson('/api/v1/driver/toggle-online')
        ->assertStatus(422)
        ->assertJsonPath('reasons.0', fn (string $reason) => str_contains($reason, 'Your car is shared on shifts. Your next shift starts'));

    $this->travelTo(Carbon::parse('2026-10-19 08:16:00', 'UTC'));

    ($this->as)($this->dayToken)->postJson('/api/v1/driver/toggle-online')
        ->assertOk()
        ->assertJsonPath('driver.is_online', true)
        ->assertJsonPath('shift.status', 'active')
        ->assertJsonPath('driver.vehicle.plate_number', 'LAG-100AA');

    expect($this->car->fresh()->driver_id)->toBe($this->day->id);
});

it('waits for the previous driver to hand the car over', function () {
    $this->car->update(['driver_id' => $this->night->id]);
    $this->night->update(['is_online' => true]);
    ($this->shift)($this->night, '2026-10-18 23:00', '2026-10-19 07:00', ['status' => DriverShiftStatus::Active]);
    ($this->shift)($this->day, '2026-10-19 07:00', '2026-10-19 15:00');

    ($this->as)($this->dayToken)->postJson('/api/v1/driver/toggle-online')
        ->assertStatus(422)
        ->assertJsonPath('reasons.0', 'LAG-100AA is still with Kemi, who is online. You can go online once the car is handed over.');

    $this->night->update(['is_online' => false]);

    ($this->as)($this->dayToken)->postJson('/api/v1/driver/toggle-online')->assertOk();

    expect($this->car->fresh()->driver_id)->toBe($this->day->id);

    // Kemi's shift is over and she no longer holds a car.
    ($this->as)($this->nightToken)->postJson('/api/v1/driver/toggle-online')
        ->assertStatus(422)
        ->assertJsonPath('reasons.0', 'No vehicle registered.');

    // Bayo holds the car but has no shift later on.
    $this->travelTo(Carbon::parse('2026-10-19 16:00:00', 'UTC'));
    $this->day->refresh()->update(['is_online' => false]);
    ($this->as)($this->dayToken)->postJson('/api/v1/driver/toggle-online')
        ->assertStatus(422)
        ->assertJsonPath('reasons.0', 'Your car is shared on shifts and you have no shift now. Contact fleet operations for your next shift.');
});

it('does not apply shifts to drivers of their own car, and stops applying them when turned off', function () {
    $user = User::factory()->create(['type' => UserType::Driver]);
    $own = Driver::factory()->approved()->create(['user_id' => $user->id, 'city_id' => $this->city->id]);
    Vehicle::factory()->create(['driver_id' => $own->id, 'vehicle_class_id' => $this->vehicleClass->id]);

    ($this->as)($user->createToken('auth', ['driver'])->plainTextToken)->postJson('/api/v1/driver/toggle-online')
        ->assertOk()
        ->assertJsonPath('shift', null);

    $this->car->update(['driver_id' => $this->day->id]);
    AppSetting::setValue('driver_scheduling.shifts_enabled', 'false', 'driver_scheduling');

    ($this->as)($this->dayToken)->postJson('/api/v1/driver/toggle-online')->assertOk();
});

// === MATCHING ===

it('stops new offers 15 minutes before the shift ends', function () {
    $this->car->update(['driver_id' => $this->day->id]);
    $this->day->update(['is_online' => true]);
    ($this->shift)($this->day, '2026-10-19 06:00', '2026-10-19 08:20', ['status' => DriverShiftStatus::Active]);

    $user = User::factory()->create(['type' => UserType::Driver]);
    $other = Driver::factory()->online()->create(['user_id' => $user->id, 'city_id' => $this->city->id]);
    Vehicle::factory()->create(['driver_id' => $other->id, 'vehicle_class_id' => $this->vehicleClass->id]);

    $this->nearby = [
        ['driver_id' => $this->day->id, 'distance_km' => 1.0],
        ['driver_id' => $other->id, 'distance_km' => 2.0],
    ];

    $ride = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'status' => RideStatus::Searching,
    ]);

    $matching = app(DriverMatchingService::class);

    // 08:00 — 20 minutes left: still offered.
    expect(collect($matching->findEligibleDrivers($ride, 5))->pluck('driver_id')->all())->toBe([$this->day->id, $other->id]);

    // 08:06 — 14 minutes left: no new offers for the shift driver.
    $this->travelTo(Carbon::parse('2026-10-19 08:06:00', 'UTC'));
    expect(collect($matching->findEligibleDrivers($ride, 5))->pluck('driver_id')->all())->toBe([$other->id]);
});

// === SHIFT JOB ===

it('marks a shift missed after 30 minutes and alerts operations once', function () {
    NotificationFacade::fake();
    $shift = ($this->shift)($this->day, '2026-10-19 07:30', '2026-10-19 15:30');
    ($this->shift)($this->night, '2026-10-19 07:45', '2026-10-19 15:45', ['vehicle_id' => Vehicle::factory()->fleet()->create(['driver_id' => null, 'shift_mode' => true])->id]);

    (new ProcessDriverShiftsJob)->handle(app(DriverShiftService::class), app(DriverLocationService::class), app(OperationsAlertService::class));
    (new ProcessDriverShiftsJob)->handle(app(DriverShiftService::class), app(DriverLocationService::class), app(OperationsAlertService::class));

    expect($shift->fresh()->status)->toBe(DriverShiftStatus::Missed)
        ->and(DriverShift::where('driver_id', $this->night->id)->value('status'))->toBe(DriverShiftStatus::Scheduled)
        ->and(Notification::where('user_id', $this->day->user_id)->where('type', 'driver_shift_missed')->count())->toBe(1);

    NotificationFacade::assertSentTo($this->admin, OperationsAlertNotification::class, fn ($n) => $n->label === 'Shift missed' && count($n->lines) === 1);
    NotificationFacade::assertSentToTimes($this->admin, OperationsAlertNotification::class, 1);

    // A missed shift can still be started late.
    ($this->as)($this->dayToken)->postJson('/api/v1/driver/toggle-online')->assertOk()->assertJsonPath('shift.status', 'active');
});

it('ends a shift: sets the driver offline, but lets a trip finish first', function () {
    $run = fn () => app()->call([new ProcessDriverShiftsJob, 'handle']);

    $this->car->update(['driver_id' => $this->day->id]);
    $this->day->update(['is_online' => true]);
    $shift = ($this->shift)($this->day, '2026-10-19 00:00', '2026-10-19 08:10', ['status' => DriverShiftStatus::Active]);

    // 08:00: offers have stopped, the driver is told once.
    $run();
    $run();
    expect(Notification::where('user_id', $this->day->user_id)->where('type', 'driver_shift_ending')->count())->toBe(1);

    // 08:15 on a trip: the shift waits for the trip.
    $this->travelTo(Carbon::parse('2026-10-19 08:15:00', 'UTC'));
    $trip = Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->day->user_id,
        'status' => RideStatus::InProgress,
    ]);
    $run();
    expect($shift->fresh()->status)->toBe(DriverShiftStatus::Active)
        ->and($this->day->fresh()->is_online)->toBeTrue();

    $trip->update(['status' => RideStatus::Completed]);
    $run();

    expect($shift->fresh()->status)->toBe(DriverShiftStatus::Completed)
        ->and($this->day->fresh()->is_online)->toBeFalse()
        ->and(Notification::where('user_id', $this->day->user_id)->where('type', 'driver_shift_ended')->exists())->toBeTrue();
});

it('keeps a driver online straight into their next shift on the same car', function () {
    $this->car->update(['driver_id' => $this->day->id]);
    $this->day->update(['is_online' => true]);
    $first = ($this->shift)($this->day, '2026-10-19 00:00', '2026-10-19 08:00', ['status' => DriverShiftStatus::Active]);
    $second = ($this->shift)($this->day, '2026-10-19 08:00', '2026-10-19 16:00');

    app()->call([new ProcessDriverShiftsJob, 'handle']);

    expect($first->fresh()->status)->toBe(DriverShiftStatus::Completed)
        ->and($second->fresh()->status)->toBe(DriverShiftStatus::Active)
        ->and($this->day->fresh()->is_online)->toBeTrue();
});

it('tells the next driver when the car is late because the previous driver is on a trip', function () {
    $this->car->update(['driver_id' => $this->night->id]);
    $this->night->update(['is_online' => true]);
    Ride::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->night->user_id,
        'status' => RideStatus::InProgress,
    ]);
    ($this->shift)($this->day, '2026-10-19 08:10', '2026-10-19 16:00');

    app()->call([new ProcessDriverShiftsJob, 'handle']);
    app()->call([new ProcessDriverShiftsJob, 'handle']);

    expect(Notification::where('user_id', $this->day->user_id)->where('type', 'shift_handover_delayed')->count())->toBe(1);
});

// === DRIVER VIEW ===

it('shows the driver their shifts and rules', function () {
    ($this->shift)($this->day, '2026-10-19 07:50', '2026-10-19 15:50');
    ($this->shift)($this->day, '2026-10-20 07:50', '2026-10-20 15:50');
    ($this->shift)($this->day, '2026-10-21 07:50', '2026-10-21 15:50', ['status' => DriverShiftStatus::Cancelled]);

    ($this->as)($this->dayToken)->getJson('/api/v1/driver/shifts')
        ->assertOk()
        ->assertJsonPath('shift_driver', true)
        ->assertJsonPath('current_shift.starts_at', '2026-10-19T07:50:00+00:00')
        ->assertJsonPath('rules.online_lead_minutes', 15)
        ->assertJsonCount(2, 'shifts')
        ->assertJsonMissingPath('shifts.0.driver');
});

// === SCHEDULED-RIDE RESERVATIONS ===

it('lets a shift driver reserve only pickups inside their shifts, and releases them when the shift is cancelled', function () {
    $shift = ($this->shift)($this->day, '2026-10-19 10:00', '2026-10-19 18:00');

    $book = fn (string $at) => ($this->as)($this->passengerToken)->postJson('/api/v1/rides', [
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'pickup_address' => '123 Test Street',
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'destination_address' => '456 Dest Street',
        'payment_method' => 'cash',
        'scheduled_pickup_at' => $at,
    ])->assertCreated()->json('ride.id');

    $inside = $book('2026-10-19T12:00:00Z');
    $outside = $book('2026-10-19T20:00:00Z');

    ($this->as)($this->dayToken)->getJson('/api/v1/driver/scheduled-rides/available')
        ->assertOk()
        ->assertJsonCount(1, 'rides')
        ->assertJsonPath('rides.0.id', $inside);

    ($this->as)($this->dayToken)->postJson("/api/v1/driver/scheduled-rides/{$outside}/reserve")
        ->assertStatus(422)
        ->assertJsonPath('errors.ride.0', 'This pickup is outside your shifts.');
    ($this->as)($this->dayToken)->postJson("/api/v1/driver/scheduled-rides/{$inside}/reserve")->assertOk();

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/driver-shifts/{$shift->id}/cancel", ['reason' => 'Car in the workshop'])->assertOk();

    expect(ScheduledRide::where('ride_id', $inside)->value('reserved_driver_id'))->toBeNull()
        ->and(ScheduledRide::where('ride_id', $inside)->value('reservation_released_reason'))->toBe('shift_changed');
});

// === AVAILABILITY PLANS ===

it('lets drivers plan their hours, repeat them weekly and remove them', function () {
    $user = User::factory()->create(['type' => UserType::Driver]);
    $driver = Driver::factory()->approved()->create(['user_id' => $user->id, 'city_id' => $this->city->id]);
    Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => $this->vehicleClass->id]);
    $token = $user->createToken('auth', ['driver'])->plainTextToken;

    ($this->as)($token)->postJson('/api/v1/driver/availability-plans', [
        'starts_at' => '2026-10-23T18:00:00+01:00',
        'ends_at' => '2026-10-23T21:00:00+01:00',
        'area_label' => '  lekki   phase 1 ',
        'repeat_weekly' => 1,
    ])->assertCreated()
        ->assertJsonCount(2, 'plans')
        ->assertJsonPath('plans.0.area_label', 'Lekki Phase 1')
        ->assertJsonPath('plans.1.starts_at', '2026-10-30T17:00:00+00:00');

    ($this->as)($token)->postJson('/api/v1/driver/availability-plans', [
        'starts_at' => '2026-10-23T20:00:00+01:00',
        'ends_at' => '2026-10-23T23:00:00+01:00',
    ])->assertStatus(422)->assertJsonValidationErrors('starts_at');

    ($this->as)($token)->postJson('/api/v1/driver/availability-plans', [
        'starts_at' => '2026-11-20T08:00:00+01:00',
        'ends_at' => '2026-11-20T10:00:00+01:00',
    ])->assertStatus(422)->assertJsonValidationErrors('starts_at');

    $planId = ($this->as)($token)->getJson('/api/v1/driver/availability-plans')->assertOk()->assertJsonCount(2, 'plans')->json('plans.0.id');

    ($this->as)($this->dayToken)->deleteJson("/api/v1/driver/availability-plans/{$planId}")->assertNotFound();
    ($this->as)($token)->deleteJson("/api/v1/driver/availability-plans/{$planId}")->assertOk();

    expect(DriverAvailabilityPlan::where('driver_id', $driver->id)->count())->toBe(1);

    // Plans never decide whether a driver can go online.
    ($this->as)($token)->postJson('/api/v1/driver/toggle-online')->assertOk();
});

it('shows operations planned drivers per hour and area', function () {
    $plan = function (string $start, string $end, ?string $area) {
        $driver = Driver::factory()->approved()->create(['city_id' => $this->city->id]);
        DriverAvailabilityPlan::create([
            'driver_id' => $driver->id,
            'city_id' => $this->city->id,
            'area_label' => $area,
            'starts_at' => Carbon::parse($start, 'UTC'),
            'ends_at' => Carbon::parse($end, 'UTC'),
        ]);
    };

    $plan('2026-10-23 17:00', '2026-10-23 20:00', 'Lekki');
    $plan('2026-10-23 18:00', '2026-10-23 19:00', 'Lekki');
    $plan('2026-10-23 18:30', '2026-10-23 22:00', 'Ikeja');

    $coverage = ($this->as)($this->adminToken)->getJson('/api/v1/admin/availability-coverage?'.http_build_query([
        'city_id' => $this->city->id,
        'from' => '2026-10-23T17:00:00Z',
        'to' => '2026-10-23T21:00:00Z',
    ]))->assertOk()->json('coverage');

    expect($coverage['hours'])->toHaveCount(4)
        ->and($coverage['hours'][0]['planned_drivers'])->toBe(1)
        ->and($coverage['hours'][1]['planned_drivers'])->toBe(3)
        ->and($coverage['hours'][1]['areas'][0])->toBe(['area_label' => 'Lekki', 'planned_drivers' => 2])
        ->and($coverage['hours'][3]['planned_drivers'])->toBe(1)
        ->and($coverage['peak_hour']['starts_at'])->toBe('2026-10-23T19:00:00+01:00')
        ->and($coverage['drivers_with_plans'])->toBe(3)
        ->and($coverage['areas'])->toBe(['Ikeja', 'Lekki']);

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/availability-coverage?'.http_build_query([
        'city_id' => $this->city->id,
        'from' => '2026-10-23T00:00:00Z',
        'to' => '2026-11-23T00:00:00Z',
    ]))->assertStatus(422);
});

// === SETTINGS ===

it('lets operations change the scheduling settings', function () {
    ($this->as)($this->adminToken)->getJson('/api/v1/admin/settings/driver-scheduling')
        ->assertOk()
        ->assertJsonPath('settings.shift_offer_cutoff_minutes', 15);

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/driver-scheduling', ['shift_offer_cutoff_minutes' => 30, 'shift_online_lead_minutes' => 10])
        ->assertOk()
        ->assertJsonPath('settings.shift_offer_cutoff_minutes', 30)
        ->assertJsonPath('settings.shift_online_lead_minutes', 10);

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/driver-scheduling', ['shift_min_minutes' => 600, 'shift_max_hours' => 8])
        ->assertStatus(422);
});
