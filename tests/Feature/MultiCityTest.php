<?php

use App\Contracts\MapsGateway;
use App\Enums\AdminRole;
use App\Enums\CancellationReason;
use App\Enums\CityLaunchStatus;
use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\CityWaitlistEntry;
use App\Models\Driver;
use App\Models\EvChargingStation;
use App\Models\Notification;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\CityBoundaryService;
use App\Services\ScheduledRideService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
| Phase 2 multi-city support (OQ-22): onboarding / live / paused, the
| readiness checklist, boundary overlaps, pausing with bookings, the
| waitlist and moving drivers between cities.
*/

beforeEach(function () {
    // Monday 19 Oct 2026, 08:00 UTC (09:00 in Lagos).
    $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));

    $this->admin = User::factory()->admin(AdminRole::Operations)->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->vehicleClass = VehicleClass::factory()->create();

    // Lagos, the launch city: live, priced, around (3.3792, 6.5244).
    $this->lagos = City::factory()->create([
        'name' => 'Lagos',
        'boundary' => ['type' => 'Point', 'coordinates' => [3.3792, 6.5244], 'radius_km' => 40],
    ]);
    $this->lagos->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);
    PricingConfig::factory()->create([
        'city_id' => $this->lagos->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    AppSetting::setValue('cities.launch_min_approved_drivers', 2, 'cities');

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $maps->shouldReceive('reverseGeocode')->andReturn(['address' => 'Somewhere, Nigeria']);
    $this->app->instance(MapsGateway::class, $maps);

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->asAdmin = fn () => ($this->as)($this->adminToken);

    $this->asGuest = function () {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken();
    };

    // A new city (Abuja) in onboarding, with a boundary, a priced class,
    // approved drivers and the manual gates confirmed: everything but
    // whatever the test leaves out.
    $this->readyCity = function (array $skip = []): City {
        $city = City::factory()->onboarding()->create([
            'name' => 'Abuja',
            'boundary' => in_array('boundary', $skip, true) ? null : ['type' => 'Point', 'coordinates' => [7.4951, 9.0579], 'radius_km' => 30],
            'launch_date' => in_array('launch_date', $skip, true) ? null : '2026-12-01',
        ]);
        $city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

        if (! in_array('pricing', $skip, true)) {
            PricingConfig::factory()->create([
                'city_id' => $city->id,
                'vehicle_class_id' => $this->vehicleClass->id,
                'effective_from' => now()->subDay(),
                'created_by_admin_id' => $this->admin->id,
            ]);
        }

        if (! in_array('drivers', $skip, true)) {
            Driver::factory()->approved()->count(2)->create(['city_id' => $city->id]);
        }

        if (! in_array('manual', $skip, true)) {
            $city->update(['readiness_confirmations' => [
                'support_cover' => ['confirmed_at' => now()->toIso8601String(), 'confirmed_by_admin_id' => $this->admin->id, 'note' => null, 'value' => null],
                'permits' => ['confirmed_at' => now()->toIso8601String(), 'confirmed_by_admin_id' => $this->admin->id, 'note' => null, 'value' => 'not_required'],
            ]]);
        }

        return $city->fresh();
    };

    $this->book = function (City $city, array $overrides = []) {
        return ($this->as)($this->passengerToken)->postJson('/api/v1/rides', [
            'city_id' => $city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'pickup_address' => '123 Test Street',
            'destination_lat' => 6.4541,
            'destination_lng' => 3.3947,
            'destination_address' => '456 Dest Street',
            'payment_method' => 'cash',
            ...$overrides,
        ]);
    };
});

// === LAUNCH STATES ===

it('creates new cities in onboarding, hidden from passengers but open to driver sign-up', function () {
    $response = ($this->asAdmin)()->postJson('/api/v1/admin/cities', [
        'name' => 'Ibadan',
        'state' => 'Oyo',
        'boundary' => ['type' => 'Point', 'coordinates' => [3.9470, 7.3775], 'radius_km' => 20],
        'launch_date' => '2027-01-15',
    ]);

    $response->assertCreated()
        ->assertJsonPath('city.launch_status', 'onboarding')
        ->assertJsonPath('city.is_active', false)
        ->assertJsonPath('city.launch_date', '2027-01-15');

    ($this->asGuest)()->getJson('/api/v1/cities')->assertOk()
        ->assertJsonCount(1, 'cities')
        ->assertJsonPath('cities.0.name', 'Lagos');

    ($this->asGuest)()->getJson('/api/v1/cities?include_onboarding=1')->assertOk()
        ->assertJsonCount(2, 'cities')
        ->assertJsonPath('cities.0.launch_status', 'onboarding')
        ->assertJsonMissingPath('cities.0.launch_date');
});

it('keeps is_active equal to live and maps legacy is_active writes to a launch status', function () {
    $new = City::factory()->inactive()->create();
    expect($new->launch_status)->toBe(CityLaunchStatus::Onboarding)->and($new->is_active)->toBeFalse();

    $this->lagos->update(['is_active' => false]);
    expect($this->lagos->fresh()->launch_status)->toBe(CityLaunchStatus::Paused);

    $this->lagos->update(['launch_status' => CityLaunchStatus::Live]);
    expect($this->lagos->fresh()->is_active)->toBeTrue()
        ->and($this->lagos->fresh()->launched_at)->not->toBeNull();
});

it('filters admin cities by launch status and counts the waitlist', function () {
    $abuja = City::factory()->onboarding()->create();
    City::factory()->paused()->create();
    CityWaitlistEntry::create(['city_id' => $abuja->id, 'user_id' => $this->passenger->id]);

    ($this->asAdmin)()->getJson('/api/v1/admin/cities?launch_status=onboarding,paused')
        ->assertOk()
        ->assertJsonCount(2, 'cities');

    ($this->asAdmin)()->getJson('/api/v1/admin/cities?launch_status=onboarding')
        ->assertOk()
        ->assertJsonCount(1, 'cities')
        ->assertJsonPath('cities.0.waitlist_count', 1);
});

it('refuses estimates and bookings outside live cities with a clear message', function () {
    $abuja = City::factory()->onboarding()->create(['name' => 'Abuja']);
    $abuja->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    ($this->book)($abuja)->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id' => 'E-tiGo is not available in Abuja yet. Join the waitlist']);

    $this->lagos->update(['launch_status' => CityLaunchStatus::Paused]);

    ($this->book)($this->lagos)->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id' => 'Rides in Lagos are paused for now.']);
});

// === ONBOARDING ANOTHER CITY (BE2-CITY-01) ===

it('onboards and launches a second city without schema changes, leaving the first untouched', function () {
    Queue::fake();
    $abuja = ($this->readyCity)();

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/launch-status", ['status' => 'live'])
        ->assertOk()
        ->assertJsonPath('message', 'Abuja is live.')
        ->assertJsonPath('city.launch_status', 'live')
        ->assertJsonPath('previous_status', 'onboarding');

    $abuja->refresh();
    expect($abuja->is_active)->toBeTrue()->and($abuja->launched_at)->not->toBeNull();

    ($this->book)($abuja, [
        'pickup_lat' => 9.0579, 'pickup_lng' => 7.4951,
        'destination_lat' => 9.0765, 'destination_lng' => 7.3986,
    ])->assertCreated()->assertJsonPath('ride.city.id', $abuja->id);

    $this->getJson('/api/v1/cities')->assertOk()->assertJsonCount(2, 'cities');
    $this->getJson("/api/v1/cities/{$abuja->id}/vehicle-classes")->assertOk()->assertJsonCount(1, 'vehicle_classes');

    // Lagos still quotes, on its own pricing.
    ($this->as)($this->passengerToken)->postJson('/api/v1/rides/estimate', [
        'city_id' => $this->lagos->id,
        'pickup_lat' => 6.5244, 'pickup_lng' => 3.3792,
        'destination_lat' => 6.4541, 'destination_lng' => 3.3947,
    ])->assertOk();
    $this->assertDatabaseHas('audit_logs', ['event' => 'city_launched']);
});

// === READINESS CHECKLIST (BE2-CITY-07) ===

it('blocks a first launch until every required gate passes', function () {
    $abuja = ($this->readyCity)(['boundary', 'pricing', 'drivers', 'manual', 'launch_date']);

    $response = ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/launch-status", ['status' => 'live']);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Abuja is not ready to launch. Complete the readiness checklist first.')
        ->assertJsonPath('readiness.ready', false)
        ->assertJsonPath('readiness.missing', ['boundary', 'pricing', 'drivers', 'support_cover', 'permits', 'launch_date']);

    expect($abuja->fresh()->launch_status)->toBe(CityLaunchStatus::Onboarding);
});

it('reports each gate with its detail', function () {
    $abuja = ($this->readyCity)(['drivers']);
    Driver::factory()->approved()->create(['city_id' => $abuja->id]);
    // Not counted: pending review, and approved but KYC not verified.
    Driver::factory()->create(['city_id' => $abuja->id]);
    Driver::factory()->approved()->create(['city_id' => $abuja->id, 'kyc_status' => 'in_progress']);

    $response = ($this->asAdmin)()->getJson("/api/v1/admin/cities/{$abuja->id}/readiness");

    $response->assertOk()
        ->assertJsonPath('readiness.ready', false)
        ->assertJsonPath('readiness.missing', ['drivers'])
        ->assertJsonPath('readiness.counts.approved_drivers', 1)
        ->assertJsonPath('readiness.counts.min_approved_drivers', 2);

    $gates = collect($response->json('readiness.gates'))->keyBy('gate');
    expect($gates['drivers'])->toMatchArray(['status' => 'failed', 'approved' => 1, 'minimum' => 2, 'kind' => 'computed'])
        ->and($gates['boundary']['status'])->toBe('passed')
        ->and($gates['pricing']['status'])->toBe('passed')
        ->and($gates['ev_charging']['status'])->toBe('not_applicable')
        ->and($gates['permits'])->toMatchArray(['status' => 'passed', 'kind' => 'manual', 'detail' => 'Confirmed: no permit is required in this state.'])
        ->and($gates['launch_promos'])->toMatchArray(['status' => 'failed', 'required' => false]);
});

it('takes the driver minimum from the settings', function () {
    $abuja = ($this->readyCity)();
    AppSetting::setValue('cities.launch_min_approved_drivers', 5, 'cities');

    ($this->asAdmin)()->getJson("/api/v1/admin/cities/{$abuja->id}/readiness")
        ->assertJsonPath('readiness.missing', ['drivers']);

    ($this->asAdmin)()->putJson('/api/v1/admin/settings/cities', ['launch_min_approved_drivers' => 2])
        ->assertOk()
        ->assertJsonPath('settings.launch_min_approved_drivers', 2);

    ($this->asAdmin)()->getJson("/api/v1/admin/cities/{$abuja->id}/readiness")
        ->assertJsonPath('readiness.ready', true);
});

it('needs a charging station when the city has plug-in drivers', function () {
    $abuja = ($this->readyCity)();
    $driver = Driver::factory()->approved()->create(['city_id' => $abuja->id]);
    Vehicle::factory()->electric()->create(['driver_id' => $driver->id]);

    ($this->asAdmin)()->getJson("/api/v1/admin/cities/{$abuja->id}/readiness")
        ->assertJsonPath('readiness.missing', ['ev_charging']);

    EvChargingStation::factory()->create(['city_id' => $abuja->id]);

    ($this->asAdmin)()->getJson("/api/v1/admin/cities/{$abuja->id}/readiness")
        ->assertJsonPath('readiness.ready', true);
});

it('lets an admin confirm and clear manual gates', function () {
    $abuja = ($this->readyCity)(['manual']);

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/readiness/permits", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('value');

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/readiness/permits", ['value' => 'obtained', 'note' => 'FCT permit ref 123'])
        ->assertOk();

    $response = ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/readiness/support_cover", ['note' => 'Night shift staffed']);
    $response->assertOk()->assertJsonPath('readiness.ready', true);

    $confirmation = $abuja->fresh()->readiness_confirmations['support_cover'];
    expect($confirmation['confirmed_by_admin_id'])->toBe($this->admin->id)
        ->and($confirmation['note'])->toBe('Night shift staffed');

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/readiness/support_cover", ['confirmed' => false])
        ->assertOk()
        ->assertJsonPath('readiness.missing', ['support_cover']);

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/readiness/drivers", [])
        ->assertUnprocessable();

    $this->assertDatabaseHas('audit_logs', ['event' => 'city_readiness_confirmed']);
    $this->assertDatabaseHas('audit_logs', ['event' => 'city_readiness_cleared']);
});

it('makes the legacy switch run the readiness checklist for a first launch', function () {
    $abuja = ($this->readyCity)(['drivers']);

    ($this->asAdmin)()->patchJson("/api/v1/admin/cities/{$abuja->id}/status")
        ->assertUnprocessable()
        ->assertJsonPath('readiness.missing', ['drivers']);
});

// === TRANSITIONS ===

it('refuses transitions that do not exist', function () {
    $abuja = City::factory()->onboarding()->create();

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/launch-status", ['status' => 'paused', 'reason' => 'x'])
        ->assertUnprocessable()
        ->assertJsonPath('message', "{$abuja->name} cannot move from Onboarding to Paused.");

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$this->lagos->id}/launch-status", ['status' => 'onboarding'])
        ->assertUnprocessable();

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$this->lagos->id}/launch-status", ['status' => 'live'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Lagos is already Live.');
});

it('requires a reason to pause and only takes scheduled_rides for a pause', function () {
    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$this->lagos->id}/launch-status", ['status' => 'paused'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $paused = City::factory()->paused()->create();
    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$paused->id}/launch-status", ['status' => 'live', 'scheduled_rides' => 'cancel'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_rides');
});

it('resumes a paused city without the readiness checklist', function () {
    Queue::fake();
    $this->lagos->update(['launch_status' => CityLaunchStatus::Paused, 'paused_at' => now(), 'pause_reason' => 'Flooding']);

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$this->lagos->id}/launch-status", ['status' => 'live'])
        ->assertOk()
        ->assertJsonPath('message', 'Lagos has resumed.')
        ->assertJsonPath('city.pause_reason', null)
        ->assertJsonPath('readiness.ready', false);

    $this->assertDatabaseHas('audit_logs', ['event' => 'city_resumed']);
});

// === PAUSING (BE2-CITY-04) ===

it('pauses a city: trips in progress finish and bookings still go ahead by default', function () {
    Queue::fake();
    $tripInProgress = Ride::factory()->inProgress()->create(['city_id' => $this->lagos->id]);

    $bookingId = ($this->book)($this->lagos, ['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])
        ->assertCreated()->json('ride.id');

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$this->lagos->id}/launch-status", [
        'status' => 'paused',
        'reason' => 'Flooding on the mainland',
    ])->assertOk()
        ->assertJsonPath('message', 'Lagos is paused.')
        ->assertJsonPath('scheduled_rides', 'honour')
        ->assertJsonPath('scheduled_rides_to_cancel', 0)
        ->assertJsonPath('active_rides', 1)
        ->assertJsonPath('city.pause_reason', 'Flooding on the mainland');

    expect($tripInProgress->fresh()->status)->toBe(RideStatus::InProgress);

    $booking = Ride::with('scheduledRide')->find($bookingId);
    expect($booking->status)->toBe(RideStatus::Scheduled);

    $this->travelTo($booking->scheduledRide->scheduled_pickup_at->copy()->subMinutes(10));
    expect(app(ScheduledRideService::class)->dispatch($booking->scheduledRide->id))->toBe('dispatched');

    // No new bookings meanwhile.
    ($this->book)($this->lagos)->assertUnprocessable();
});

it('cancels upcoming bookings when the admin chooses, telling each passenger', function () {
    // The follow-up job runs inline (sync queue).
    $bookingId = ($this->book)($this->lagos, ['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])
        ->assertCreated()->json('ride.id');

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$this->lagos->id}/launch-status", [
        'status' => 'paused',
        'reason' => 'Fuel scarcity',
        'scheduled_rides' => 'cancel',
    ])->assertOk()
        ->assertJsonPath('scheduled_rides', 'cancel')
        ->assertJsonPath('scheduled_rides_to_cancel', 1);

    $booking = Ride::find($bookingId);
    expect($booking->status)->toBe(RideStatus::Cancelled)
        ->and($booking->cancellation_reason)->toBe(CancellationReason::CityPaused->value);

    $notice = Notification::where('user_id', $this->passenger->id)->where('type', 'ride_cancelled')->first();
    expect($notice?->body)->toBe('Your scheduled ride was cancelled because E-tiGo has paused rides in Lagos.');
});

it('refuses to dispatch a booking that slipped through a cancelling pause', function () {
    Queue::fake();
    $bookingId = ($this->book)($this->lagos, ['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])
        ->assertCreated()->json('ride.id');

    $this->lagos->update(['launch_status' => CityLaunchStatus::Paused, 'pause_scheduled_rides' => 'cancel']);

    $booking = Ride::with('scheduledRide')->find($bookingId);
    $this->travelTo($booking->scheduledRide->scheduled_pickup_at->copy()->subMinutes(10));

    expect(app(ScheduledRideService::class)->dispatch($booking->scheduledRide->id))->toBe('failed')
        ->and($booking->scheduledRide->fresh()->dispatch_failure_reason)->toBe('city_paused');
});

it('tells the city drivers when it goes live or pauses', function () {
    $abuja = ($this->readyCity)();
    $driver = Driver::where('city_id', $abuja->id)->first();

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/launch-status", ['status' => 'live'])->assertOk();

    expect(Notification::where('user_id', $driver->user_id)->where('type', 'city_status_changed')->value('title'))
        ->toBe('E-tiGo is live in Abuja');
});

// === WAITLIST ===

it('lets a passenger join the waitlist of a city that is not open, and tells them on launch', function () {
    $abuja = ($this->readyCity)();

    ($this->as)($this->passengerToken)->postJson("/api/v1/cities/{$abuja->id}/waitlist")
        ->assertCreated()
        ->assertJsonPath('on_waitlist', true)
        ->assertJsonPath('city.launch_status', 'onboarding');

    ($this->as)($this->passengerToken)->postJson("/api/v1/cities/{$abuja->id}/waitlist")->assertOk();
    expect(CityWaitlistEntry::count())->toBe(1);

    ($this->asAdmin)()->getJson("/api/v1/admin/cities/{$abuja->id}")
        ->assertJsonPath('city.waitlist_count', 1)
        ->assertJsonPath('readiness.counts.waitlist', 1);

    ($this->asAdmin)()->postJson("/api/v1/admin/cities/{$abuja->id}/launch-status", ['status' => 'live'])->assertOk();

    $notice = Notification::where('user_id', $this->passenger->id)->where('type', 'city_launched')->first();
    expect($notice?->title)->toBe('E-tiGo is now in Abuja')
        ->and(CityWaitlistEntry::first()->notified_at)->not->toBeNull();

    ($this->as)($this->passengerToken)->getJson("/api/v1/cities/{$abuja->id}/waitlist")
        ->assertOk()
        ->assertJsonPath('on_waitlist', false);

    ($this->as)($this->passengerToken)->postJson("/api/v1/cities/{$abuja->id}/waitlist")
        ->assertUnprocessable();
});

it('lets a passenger leave the waitlist', function () {
    $abuja = City::factory()->onboarding()->create();
    CityWaitlistEntry::create(['city_id' => $abuja->id, 'user_id' => $this->passenger->id]);

    ($this->as)($this->passengerToken)->deleteJson("/api/v1/cities/{$abuja->id}/waitlist")
        ->assertOk()
        ->assertJsonPath('on_waitlist', false);

    expect(CityWaitlistEntry::count())->toBe(0);
});

it('points a passenger in a city that is not open to the waitlist when detecting their city', function () {
    City::factory()->onboarding()->create([
        'name' => 'Abuja',
        'boundary' => ['type' => 'Point', 'coordinates' => [7.4951, 9.0579], 'radius_km' => 30],
    ]);

    $this->getJson('/api/v1/cities/detect?lat=9.06&lng=7.49')
        ->assertNotFound()
        ->assertJsonPath('unavailable_city.name', 'Abuja')
        ->assertJsonPath('unavailable_city.launch_status', 'onboarding')
        ->assertJsonPath('unavailable_city.waitlist_open', true);

    $this->getJson('/api/v1/cities/detect?lat=6.52&lng=3.38')
        ->assertOk()
        ->assertJsonPath('city.name', 'Lagos');
});

// === BOUNDARIES (BE2-CITY-05) ===

it('refuses a boundary that overlaps another city', function () {
    ($this->asAdmin)()->postJson('/api/v1/admin/cities', [
        'name' => 'Ikeja',
        'state' => 'Lagos',
        'boundary' => ['type' => 'Point', 'coordinates' => [3.3515, 6.6018], 'radius_km' => 10],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['boundary' => 'This boundary overlaps Lagos.']);

    // Its own boundary never conflicts with itself.
    ($this->asAdmin)()->putJson("/api/v1/admin/cities/{$this->lagos->id}", [
        'boundary' => ['type' => 'Point', 'coordinates' => [3.3792, 6.5244], 'radius_km' => 45],
    ])->assertOk();
});

it('allows cities that share a border', function () {
    $west = [[[3.0, 6.0], [3.5, 6.0], [3.5, 6.5], [3.0, 6.5], [3.0, 6.0]]];
    $east = [[[3.5, 6.0], [4.0, 6.0], [4.0, 6.5], [3.5, 6.5], [3.5, 6.0]]];
    $overlapping = [[[3.4, 6.1], [3.9, 6.1], [3.9, 6.4], [3.4, 6.4], [3.4, 6.1]]];
    $inside = [[[3.1, 6.1], [3.2, 6.1], [3.2, 6.2], [3.1, 6.2], [3.1, 6.1]]];

    $boundaries = app(CityBoundaryService::class);
    $polygon = fn (array $coordinates) => ['type' => 'Polygon', 'coordinates' => $coordinates];

    expect($boundaries->overlaps($polygon($west), $polygon($east)))->toBeFalse()
        ->and($boundaries->overlaps($polygon($west), $polygon($overlapping)))->toBeTrue()
        ->and($boundaries->overlaps($polygon($west), $polygon($inside)))->toBeTrue()
        ->and($boundaries->overlaps($polygon($west), $polygon($west)))->toBeTrue()
        ->and($boundaries->overlaps($polygon($west), ['type' => 'Point', 'coordinates' => [3.25, 6.25], 'radius_km' => 1]))->toBeTrue()
        ->and($boundaries->overlaps($polygon($west), ['type' => 'Point', 'coordinates' => [3.7, 6.25], 'radius_km' => 5]))->toBeFalse()
        ->and($boundaries->overlaps($polygon($west), ['type' => 'Point', 'coordinates' => [3.55, 6.25], 'radius_km' => 10]))->toBeTrue();
});

it('only accepts Lagos time and naira until the Lagos-time rules follow each city', function () {
    ($this->asAdmin)()->postJson('/api/v1/admin/cities', [
        'name' => 'Accra',
        'state' => 'Lagos',
        'timezone' => 'Africa/Accra',
        'currency_code' => 'GHS',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['timezone', 'currency_code']);
});

// === DRIVERS ===

it('lets drivers pick onboarding or live cities before they apply, and not after', function () {
    $abuja = City::factory()->onboarding()->create();
    $paused = City::factory()->paused()->create();
    $driver = Driver::factory()->create(['status' => DriverStatus::Onboarding, 'city_id' => $this->lagos->id]);
    $token = $driver->user->createToken('auth', ['driver'])->plainTextToken;

    ($this->as)($token)->putJson('/api/v1/driver/profile', ['city_id' => $paused->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id' => 'The selected city is not open for driver sign-ups.']);

    ($this->as)($token)->putJson('/api/v1/driver/profile', ['city_id' => $abuja->id])->assertOk();
    expect($driver->fresh()->city_id)->toBe($abuja->id);

    $driver->update(['status' => DriverStatus::Approved]);

    ($this->as)($token)->putJson('/api/v1/driver/profile', ['city_id' => $this->lagos->id])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your city can only be changed before you submit your application. Contact support to move city.');

    // Other profile changes still work.
    ($this->as)($token)->putJson('/api/v1/driver/profile', ['licence_number' => 'ABC123', 'city_id' => $abuja->id])->assertOk();
});

it('keeps drivers offline until their city launches', function () {
    $abuja = City::factory()->onboarding()->create(['name' => 'Abuja']);
    $driver = Driver::factory()->approved()->create(['city_id' => $abuja->id]);
    Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => $this->vehicleClass->id]);
    $token = $driver->user->createToken('auth', ['driver'])->plainTextToken;

    ($this->as)($token)->postJson('/api/v1/driver/toggle-online')
        ->assertUnprocessable()
        ->assertJsonPath('reasons', ['E-tiGo has not launched in Abuja yet. You can go online from launch day.']);

    $abuja->update(['launch_status' => CityLaunchStatus::Paused]);

    ($this->as)($token)->postJson('/api/v1/driver/toggle-online')
        ->assertOk()
        ->assertJsonPath('city_notice', 'Rides in Abuja are paused. You will only get rides passengers booked before the pause.');
});

// === DRIVER CITY TRANSFER (BE2-CITY-06) ===

it('moves a driver to another city, taking them offline', function () {
    $abuja = City::factory()->onboarding()->create(['name' => 'Abuja']);
    $driver = Driver::factory()->online()->create(['city_id' => $this->lagos->id]);
    Vehicle::factory()->create(['driver_id' => $driver->id, 'vehicle_class_id' => $this->vehicleClass->id]);

    ($this->asAdmin)()->putJson("/api/v1/admin/drivers/{$driver->id}/city", ['city_id' => $abuja->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    ($this->asAdmin)()->putJson("/api/v1/admin/drivers/{$driver->id}/city", ['city_id' => $abuja->id, 'reason' => 'Relocated'])
        ->assertOk()
        ->assertJsonPath('message', 'Driver moved to Abuja.')
        ->assertJsonPath('warnings', [
            "Abuja does not offer the driver's vehicle class ({$this->vehicleClass->name}), so they will not get trips until it is offered or their class changes.",
            'Abuja has not launched yet, so the driver cannot go online until it is live.',
        ]);

    $driver->refresh();
    expect($driver->city_id)->toBe($abuja->id)->and($driver->is_online)->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['event' => 'driver_city_changed', 'auditable_id' => $driver->id]);
    expect(Notification::where('user_id', $driver->user_id)->where('type', 'driver_city_changed')->exists())->toBeTrue();
});

it('refuses to move a driver on a trip or holding reservations', function () {
    $abuja = City::factory()->create();
    $driver = Driver::factory()->approved()->create(['city_id' => $this->lagos->id]);

    $trip = Ride::factory()->inProgress()->create(['city_id' => $this->lagos->id, 'driver_id' => $driver->user_id]);

    ($this->asAdmin)()->putJson("/api/v1/admin/drivers/{$driver->id}/city", ['city_id' => $abuja->id, 'reason' => 'Relocated'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'The driver cannot change city during an active ride.');

    $trip->update(['status' => RideStatus::Completed]);
    $booking = Ride::factory()->create(['city_id' => $this->lagos->id, 'status' => RideStatus::Scheduled]);
    ScheduledRide::create([
        'ride_id' => $booking->id,
        'scheduled_pickup_at' => now()->addDay(),
        'reserved_driver_id' => $driver->user_id,
        'reserved_at' => now(),
    ]);

    ($this->asAdmin)()->putJson("/api/v1/admin/drivers/{$driver->id}/city", ['city_id' => $abuja->id, 'reason' => 'Relocated'])
        ->assertStatus(409)
        ->assertJsonPath('upcoming_reservations', 1);

    expect($driver->fresh()->city_id)->toBe($this->lagos->id);
});

// === SETTINGS ===

it('shows and updates the city settings', function () {
    ($this->asAdmin)()->getJson('/api/v1/admin/settings/cities')
        ->assertOk()
        ->assertJsonPath('settings.launch_min_approved_drivers', 2)
        ->assertJsonPath('settings.require_ev_charging', true)
        ->assertJsonPath('settings.pause_scheduled_rides', 'honour');

    ($this->asAdmin)()->putJson('/api/v1/admin/settings/cities', ['pause_scheduled_rides' => 'later'])
        ->assertUnprocessable();

    ($this->asAdmin)()->putJson('/api/v1/admin/settings/cities', ['pause_scheduled_rides' => 'cancel', 'require_ev_charging' => false])
        ->assertOk()
        ->assertJsonPath('settings.pause_scheduled_rides', 'cancel')
        ->assertJsonPath('settings.require_ev_charging', false);

    $this->assertDatabaseHas('audit_logs', ['event' => 'city_settings.updated']);
});

it('keeps city launch endpoints to operations', function () {
    $support = User::factory()->admin(AdminRole::Support)->create();
    $token = $support->createToken('auth', ['admin'])->plainTextToken;

    ($this->as)($token)->postJson("/api/v1/admin/cities/{$this->lagos->id}/launch-status", ['status' => 'paused', 'reason' => 'x'])
        ->assertForbidden();
    ($this->as)($token)->getJson('/api/v1/admin/settings/cities')->assertForbidden();
});
