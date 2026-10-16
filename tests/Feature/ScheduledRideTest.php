<?php

use App\Contracts\MapsGateway;
use App\Enums\AdminRole;
use App\Enums\HoldStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\DispatchRideRequestJob;
use App\Jobs\MatchingTimeoutJob;
use App\Jobs\ProcessScheduledRidesJob;
use App\Jobs\ScheduledRideDispatchJob;
use App\Jobs\ScheduledRideReminderJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\City;
use App\Models\Hold;
use App\Models\Notification;
use App\Models\PricingConfig;
use App\Models\PromoCode;
use App\Models\Ride;
use App\Models\RideStateTransition;
use App\Models\ScheduledRide;
use App\Models\SurgeRule;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\DriverMatchingService;
use App\Services\RidePinService;
use App\Services\ScheduledRideService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

// Fare for the mocked 8.5 km / 15 min route: 600 + 8.5 × 250 + 15 × 40.
const SCHEDULED_TEST_FARE = 3325.0;

beforeEach(function () {
    Queue::fake();

    // Monday 19 Oct 2026, 08:00 UTC (09:00 in Lagos).
    $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->token = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

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

    // The test client remembers the first user it authenticates; forget it
    // whenever a request is made as someone else.
    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->book = function (array $overrides = [], ?string $token = null) {
        return ($this->as)($token ?? $this->token)->postJson('/api/v1/rides', [
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
        ]);
    };

    $this->fundWallet = function (int $kobo) {
        return Account::factory()->passengerWallet()->withBalance($kobo)->create([
            'owner_type' => (new User)->getMorphClass(),
            'owner_id' => $this->passenger->id,
        ]);
    };

    $this->runSweep = function () {
        (new ProcessScheduledRidesJob)->handle();
    };

    $this->dispatchNow = fn (Ride $ride) => app(ScheduledRideService::class)->dispatch($ride->scheduledRide->id);
});

// === BOOKING ===

it('books a ride for later without starting matching', function () {
    $pickupAt = now()->addHours(3);

    $response = ($this->book)(['scheduled_pickup_at' => $pickupAt->copy()->setTimezone('Africa/Lagos')->toIso8601String()]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Ride scheduled successfully.')
        ->assertJsonPath('ride.status', 'scheduled')
        ->assertJsonPath('ride.is_scheduled', true)
        ->assertJsonPath('ride.scheduled.can_edit', true)
        ->assertJsonPath('pin_code', null);

    $ride = Ride::with('scheduledRide')->find($response->json('ride.id'));

    expect($ride->status)->toBe(RideStatus::Scheduled)
        ->and($ride->scheduledRide->scheduled_pickup_at->equalTo($pickupAt))->toBeTrue()
        ->and((float) $ride->fare_estimate_amount)->toBe(SCHEDULED_TEST_FARE)
        ->and(RideStateTransition::where('ride_id', $ride->id)->count())->toBe(0)
        ->and(app(RidePinService::class)->getCachedPin($ride))->toBeNull();

    Queue::assertNotPushed(DispatchRideRequestJob::class);
    Queue::assertNotPushed(MatchingTimeoutJob::class);
});

it('requires the pickup time to carry a timezone offset', function () {
    ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->format('Y-m-d\TH:i:s')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_pickup_at');
});

it('rejects a pickup time too soon or too far ahead', function () {
    ($this->book)(['scheduled_pickup_at' => now()->addMinutes(20)->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_pickup_at' => 'at least 30 minutes']);

    ($this->book)(['scheduled_pickup_at' => now()->addDays(8)->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_pickup_at' => 'at most 7 days']);
});

it('rejects new bookings while scheduling is switched off', function () {
    AppSetting::setValue('scheduled_rides.enabled', 'false', 'scheduled_rides');

    ($this->book)()
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_pickup_at' => 'not available']);
});

it('limits upcoming bookings and keeps them apart', function () {
    AppSetting::setValue('scheduled_rides.max_upcoming_per_passenger', 2, 'scheduled_rides');

    ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])->assertCreated();

    ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->addMinutes(20)->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_pickup_at' => 'within 30 minutes']);

    ($this->book)(['scheduled_pickup_at' => now()->addHours(5)->toIso8601String()])->assertCreated();

    ($this->book)(['scheduled_pickup_at' => now()->addHours(8)->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_pickup_at' => 'at most 2 upcoming']);
});

it('lets a passenger book for later during a ride and ride now with a booking pending', function () {
    Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::InProgress,
    ]);

    ($this->book)()->assertCreated();

    Ride::where('status', RideStatus::InProgress)->update(['status' => RideStatus::Completed]);

    ($this->book)(['scheduled_pickup_at' => null])
        ->assertCreated()
        ->assertJsonPath('ride.status', 'searching')
        ->assertJsonPath('ride.is_scheduled', false);
});

it('checks the wallet balance at booking but holds nothing until dispatch', function () {
    $account = ($this->fundWallet)(100_000);

    ($this->book)(['payment_method' => 'wallet'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.payment_method.0', 'Insufficient wallet balance.');

    $account->update(['balance' => 1_000_000]);

    ($this->book)(['payment_method' => 'wallet'])->assertCreated();

    expect(Hold::count())->toBe(0);
});

it('quotes a scheduled ride with the surge in effect at its pickup time', function () {
    // Morning rush in Lagos, 15:00–17:00 on Mondays; booked at 09:00 for 16:00.
    SurgeRule::factory()->timeBased([
        'days_of_week' => [1],
        'start_time' => '15:00',
        'end_time' => '17:00',
    ])->create([
        'city_id' => $this->city->id,
        'multiplier' => 1.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    // Live demand says nothing about a pickup hours away.
    SurgeRule::factory()->demandBased(0.1)->create([
        'city_id' => $this->city->id,
        'multiplier' => 3.0,
        'priority' => 10,
        'conditions' => ['min_demand_supply_ratio' => 0.1, 'min_requests' => 0],
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = ($this->book)(['scheduled_pickup_at' => '2026-10-19T16:00:00+01:00'])->assertCreated();

    expect((float) $response->json('ride.fare_estimate_amount'))->toBe(round(SCHEDULED_TEST_FARE * 1.5, 2));

    $estimate = $this->withToken($this->token)->postJson('/api/v1/rides/estimate', [
        'city_id' => $this->city->id,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'scheduled_pickup_at' => '2026-10-19T12:00:00+01:00',
    ])->assertOk();

    expect($estimate->json('estimates.0.surge.multiplier'))->toEqual(1.0);
});

it('checks a promo code against the pickup time', function () {
    PromoCode::factory()->create(['code' => 'ENDSTODAY', 'expires_at' => now()->addHours(2)]);

    ($this->book)(['promo_code' => 'ENDSTODAY', 'scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['promo_code' => 'expired']);

    ($this->book)(['promo_code' => 'ENDSTODAY', 'scheduled_pickup_at' => now()->addHour()->toIso8601String()])
        ->assertCreated();
});

// === LIST, EDIT, CANCEL ===

it('lists only the passenger\'s upcoming bookings, soonest first', function () {
    $later = ($this->book)(['scheduled_pickup_at' => now()->addDays(2)->toIso8601String()])->json('ride.id');
    $sooner = ($this->book)(['scheduled_pickup_at' => now()->addHours(4)->toIso8601String()])->json('ride.id');

    $other = User::factory()->create(['type' => UserType::Passenger]);
    ($this->book)([], $other->createToken('auth', ['passenger'])->plainTextToken)->assertCreated();

    Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Completed,
    ]);

    ($this->as)($this->token)->getJson('/api/v1/rides/scheduled')
        ->assertOk()
        ->assertJsonCount(2, 'rides')
        ->assertJsonPath('rides.0.id', $sooner)
        ->assertJsonPath('rides.1.id', $later)
        ->assertJsonPath('meta.total', 2);
});

it('edits a booking and quotes it again', function () {
    $rideId = ($this->book)()->json('ride.id');
    $newTime = now()->addDay();

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 12.0, 'duration_minutes' => 20.0]);
    $this->app->instance(MapsGateway::class, $maps);

    $this->withToken($this->token)->putJson("/api/v1/rides/{$rideId}/scheduled", [
        'scheduled_pickup_at' => $newTime->toIso8601String(),
        'destination_lat' => 6.43,
        'destination_lng' => 3.42,
        'destination_address' => 'Lekki Phase 1',
    ])
        ->assertOk()
        ->assertJsonPath('ride.destination.address', 'Lekki Phase 1')
        ->assertJsonPath('ride.status', 'scheduled');

    $ride = Ride::with('scheduledRide')->find($rideId);

    // 600 + 12 × 250 + 20 × 40
    expect((float) $ride->fare_estimate_amount)->toBe(4400.0)
        ->and($ride->scheduledRide->scheduled_pickup_at->equalTo($newTime))->toBeTrue()
        ->and($ride->scheduledRide->reminder_sent_at)->toBeNull();
});

it('requires a new place to come with its coordinates and address', function () {
    $rideId = ($this->book)()->json('ride.id');

    $this->withToken($this->token)->putJson("/api/v1/rides/{$rideId}/scheduled", ['pickup_address' => 'Somewhere'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pickup_lat', 'pickup_lng']);

    $this->withToken($this->token)->putJson("/api/v1/rides/{$rideId}/scheduled", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ride');
});

it('refuses edits from another passenger or once dispatch is near', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()])->json('ride.id');

    $other = User::factory()->create(['type' => UserType::Passenger]);
    ($this->as)($other->createToken('auth', ['passenger'])->plainTextToken)
        ->putJson("/api/v1/rides/{$rideId}/scheduled", ['payment_method' => 'card'])
        ->assertForbidden();

    $this->travel(30)->minutes();

    ($this->as)($this->token)->putJson("/api/v1/rides/{$rideId}/scheduled", ['payment_method' => 'card'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ride' => 'can no longer be changed']);
});

it('cancels a booking before dispatch and keeps it out of the sweep', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()])->json('ride.id');

    $this->withToken($this->token)->deleteJson("/api/v1/rides/{$rideId}/scheduled", ['reason' => 'changed_mind'])
        ->assertOk()
        ->assertJsonPath('ride.status', 'cancelled');

    expect(ScheduledRide::where('ride_id', $rideId)->first()->cancelled_at)->not->toBeNull();

    $this->travel(30)->minutes();
    ($this->runSweep)();

    Queue::assertNotPushed(ScheduledRideDispatchJob::class);
});

it('also cancels through the standard cancel endpoint', function () {
    $rideId = ($this->book)()->json('ride.id');

    $this->withToken($this->token)->postJson("/api/v1/rides/{$rideId}/cancel")
        ->assertOk()
        ->assertJsonPath('ride.status', 'cancelled');

    expect(ScheduledRide::where('ride_id', $rideId)->first()->cancelled_at)->not->toBeNull();
});

// === DISPATCH ===

it('queues bookings for dispatch once their lead time is reached', function () {
    $soon = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()])->json('ride.id');
    ($this->book)(['scheduled_pickup_at' => now()->addHours(5)->toIso8601String()])->assertCreated();

    ($this->runSweep)();
    Queue::assertNotPushed(ScheduledRideDispatchJob::class);

    $this->travel(26)->minutes();
    ($this->runSweep)();

    Queue::assertPushed(ScheduledRideDispatchJob::class, 1);
    Queue::assertPushed(
        ScheduledRideDispatchJob::class,
        fn ($job) => $job->scheduledRideId === ScheduledRide::where('ride_id', $soon)->value('id'),
    );
});

it('starts matching at dispatch: hold, PIN, searching until pickup plus grace', function () {
    $account = ($this->fundWallet)(1_000_000);
    $pickupAt = now()->addMinutes(40);
    $rideId = ($this->book)([
        'payment_method' => 'wallet',
        'scheduled_pickup_at' => $pickupAt->toIso8601String(),
    ])->json('ride.id');

    $this->travel(25)->minutes();
    $ride = Ride::with('scheduledRide')->find($rideId);

    expect(($this->dispatchNow)($ride))->toBe('dispatched');

    $ride->refresh()->load('scheduledRide');
    expect($ride->status)->toBe(RideStatus::Searching)
        ->and($ride->scheduledRide->dispatched_at)->not->toBeNull()
        ->and(app(RidePinService::class)->getCachedPin($ride))->toMatch('/^\d{4}$/')
        ->and(Hold::where('ride_id', $rideId)->where('status', HoldStatus::Active)->value('amount'))->toBe(332500)
        ->and(Notification::where('user_id', $this->passenger->id)->where('type', 'scheduled_ride_dispatched')->exists())->toBeTrue();

    Queue::assertPushed(DispatchRideRequestJob::class, fn ($job) => $job->rideId === $rideId);
    Queue::assertPushed(MatchingTimeoutJob::class, function ($job) use ($rideId, $pickupAt) {
        return $job->rideId === $rideId && $job->delay->equalTo($pickupAt->copy()->addMinutes(10));
    });

    // A second run (duplicate queue entry) changes nothing.
    expect(($this->dispatchNow)($ride))->toBe('skipped')
        ->and(Hold::where('ride_id', $rideId)->count())->toBe(1);

    ($this->as)($this->token)->getJson('/api/v1/passenger/active-ride')
        ->assertOk()
        ->assertJsonPath('ride.id', $rideId)
        ->assertJsonPath('ride.pin_code', app(RidePinService::class)->getCachedPin($ride));
});

it('cancels the booking with an explanation when the wallet cannot pay at dispatch', function () {
    $account = ($this->fundWallet)(1_000_000);
    $rideId = ($this->book)([
        'payment_method' => 'wallet',
        'scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String(),
    ])->json('ride.id');

    $account->update(['balance' => 1_000]);
    $this->travel(25)->minutes();

    expect(($this->dispatchNow)(Ride::with('scheduledRide')->find($rideId)))->toBe('failed');

    $ride = Ride::with('scheduledRide')->find($rideId);
    expect($ride->status)->toBe(RideStatus::Cancelled)
        ->and($ride->cancellation_reason)->toBe('scheduled_dispatch_failed')
        ->and($ride->scheduledRide->dispatch_failure_reason)->toBe('insufficient_wallet_balance');

    $notification = Notification::where('user_id', $this->passenger->id)->where('type', 'ride_cancelled')->first();
    expect($notification->body)->toContain('wallet balance could not cover the fare');

    Queue::assertNotPushed(DispatchRideRequestJob::class);
});

it('waits while the passenger is still on another ride, then gives up after the grace period', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()])->json('ride.id');

    Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::InProgress,
    ]);

    $this->travel(25)->minutes();
    expect(($this->dispatchNow)(Ride::with('scheduledRide')->find($rideId)))->toBe('waiting')
        ->and(Ride::find($rideId)->status)->toBe(RideStatus::Scheduled);

    $this->travel(30)->minutes();
    expect(($this->dispatchNow)(Ride::with('scheduledRide')->find($rideId)))->toBe('failed')
        ->and(ScheduledRide::where('ride_id', $rideId)->value('dispatch_failure_reason'))->toBe('missed_dispatch_window');
});

it('does not dispatch for a suspended passenger or a withdrawn vehicle class', function () {
    $first = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()])->json('ride.id');
    $second = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(80)->toIso8601String()])->json('ride.id');

    $this->travel(26)->minutes();

    $this->passenger->update(['is_active' => false]);
    expect(($this->dispatchNow)(Ride::with('scheduledRide')->find($first)))->toBe('failed');

    $this->travel(40)->minutes();
    $this->passenger->update(['is_active' => true]);
    $this->city->vehicleClasses()->updateExistingPivot($this->vehicleClass->id, ['is_active' => false]);
    expect(($this->dispatchNow)(Ride::with('scheduledRide')->find($second)))->toBe('failed');

    expect(ScheduledRide::where('ride_id', $first)->value('dispatch_failure_reason'))->toBe('account_suspended')
        ->and(ScheduledRide::where('ride_id', $second)->value('dispatch_failure_reason'))->toBe('service_unavailable');
});

it('keeps a dispatched booking searching in waves until the grace period ends', function () {
    $pickupAt = now()->addMinutes(40);
    $rideId = ($this->book)(['scheduled_pickup_at' => $pickupAt->toIso8601String()])->json('ride.id');
    $this->travel(25)->minutes();
    ($this->dispatchNow)(Ride::with('scheduledRide')->find($rideId));

    $matching = app(DriverMatchingService::class);
    $ride = Ride::with('scheduledRide')->find($rideId);

    expect($matching->searchDeadline($ride)->equalTo($pickupAt->copy()->addMinutes(10)))->toBeTrue()
        ->and($matching->autoRetryDelaySeconds($ride))->toBe(30);

    $matching->resetForAutoRetry($ride);
    $matching->resetForAutoRetry($ride);
    expect($matching->canAutoRetry($ride))->toBeTrue();

    // After the grace period the ride is back on the on-demand rules.
    $this->travelTo($pickupAt->copy()->addMinutes(11));
    expect($matching->searchDeadline($ride)->isPast())->toBeTrue()
        ->and($matching->autoRetryDelaySeconds($ride))->toBe(10);
});

it('does not expire a dispatched booking\'s search because it was booked long ago', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addDays(2)->toIso8601String()])->json('ride.id');

    $this->travel(2)->days();
    $this->travel(-14)->minutes();
    ($this->dispatchNow)(Ride::with('scheduledRide')->find($rideId));

    ($this->book)(['scheduled_pickup_at' => null])->assertStatus(409);

    expect(Ride::find($rideId)->status)->toBe(RideStatus::Searching);
});

// === REMINDERS ===

it('reminds the passenger once, warning when the wallet is short', function () {
    $account = ($this->fundWallet)(1_000_000);
    $rideId = ($this->book)([
        'payment_method' => 'wallet',
        'scheduled_pickup_at' => '2026-10-19T14:30:00+01:00',
    ])->json('ride.id');

    $account->update(['balance' => 1_000]);

    $this->travelTo(Carbon::parse('2026-10-19T13:00:00+01:00'));
    ($this->runSweep)();
    Queue::assertNotPushed(ScheduledRideReminderJob::class);

    $this->travelTo(Carbon::parse('2026-10-19T13:31:00+01:00'));
    ($this->runSweep)();
    Queue::assertPushed(ScheduledRideReminderJob::class, 1);

    $service = app(ScheduledRideService::class);
    $scheduledId = ScheduledRide::where('ride_id', $rideId)->value('id');

    expect($service->sendReminder($scheduledId))->toBeTrue()
        ->and($service->sendReminder($scheduledId))->toBeFalse();

    $reminder = Notification::where('type', 'scheduled_ride_reminder')->sole();
    expect($reminder->body)->toContain('2:30 PM')->toContain('Top up');
});

it('skips the separate reminder when booked inside the reminder window', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(45)->toIso8601String()])->json('ride.id');

    expect(ScheduledRide::where('ride_id', $rideId)->value('reminder_sent_at'))->not->toBeNull();

    $this->travel(5)->minutes();
    ($this->runSweep)();

    Queue::assertNotPushed(ScheduledRideReminderJob::class);
});

// === ADMIN & CONFIG ===

it('lets operations read and change the scheduling settings within safe limits', function () {
    $this->withToken($this->adminToken)->getJson('/api/v1/admin/settings/scheduled-rides')
        ->assertOk()
        ->assertJsonPath('settings.dispatch_lead_minutes', 15)
        ->assertJsonPath('settings.reminder_minutes', 60);

    $this->withToken($this->adminToken)->putJson('/api/v1/admin/settings/scheduled-rides', ['dispatch_lead_minutes' => 45])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dispatch_lead_minutes');

    $this->withToken($this->adminToken)->putJson('/api/v1/admin/settings/scheduled-rides', ['reminder_minutes' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reminder_minutes');

    $this->withToken($this->adminToken)->putJson('/api/v1/admin/settings/scheduled-rides', [
        'dispatch_lead_minutes' => 20,
        'reminder_minutes' => 0,
        'enabled' => false,
    ])
        ->assertOk()
        ->assertJsonPath('settings.dispatch_lead_minutes', 20)
        ->assertJsonPath('settings.reminder_minutes', 0)
        ->assertJsonPath('settings.enabled', false);

    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('scheduled_rides.enabled', false)
        ->assertJsonPath('scheduled_rides.dispatch_lead_minutes', 20);
});

it('keeps scheduling settings away from other admin roles', function () {
    $support = User::factory()->admin(AdminRole::Support)->create();

    ($this->as)($support->createToken('auth', ['admin'])->plainTextToken)
        ->putJson('/api/v1/admin/settings/scheduled-rides', ['dispatch_lead_minutes' => 20])
        ->assertForbidden();
});

it('lists upcoming bookings for operations', function () {
    ($this->book)(['scheduled_pickup_at' => now()->addDays(2)->toIso8601String()]);
    $sooner = ($this->book)(['scheduled_pickup_at' => now()->addHours(2)->toIso8601String()])->json('ride.id');

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/scheduled-rides')
        ->assertOk()
        ->assertJsonCount(2, 'rides')
        ->assertJsonPath('rides.0.id', $sooner)
        ->assertJsonPath('rides.0.passenger.id', $this->passenger->id);

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/scheduled-rides?to='.urlencode(now()->addDay()->toIso8601String()))
        ->assertJsonCount(1, 'rides');
});

it('keeps the dispatcher failure reason out of the cancellation choices', function () {
    $codes = collect($this->withToken($this->adminToken)->getJson('/api/v1/rides/cancellation-reasons')->json('reasons'))
        ->pluck('code');

    expect($codes)->not->toContain('scheduled_dispatch_failed')->not->toContain('system_timeout');
});

it('honours the offset in the admin pickup-time filter', function () {
    // 10:30 and 11:30 Lagos time on 19 Oct.
    ($this->book)(['scheduled_pickup_at' => '2026-10-19T10:30:00+01:00'])->assertCreated();
    ($this->book)(['scheduled_pickup_at' => '2026-10-19T11:30:00+01:00'])->assertCreated();

    ($this->as)($this->adminToken)
        ->getJson('/api/v1/admin/scheduled-rides?from='.urlencode('2026-10-19T11:00:00+01:00'))
        ->assertOk()
        ->assertJsonCount(1, 'rides');
});

it('leaves a booking cancelled before its dispatch alone', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(40)->toIso8601String()])->json('ride.id');
    $scheduled = Ride::with('scheduledRide')->find($rideId);

    $this->withToken($this->token)->deleteJson("/api/v1/rides/{$rideId}/scheduled")->assertOk();
    $this->travel(26)->minutes();

    expect(app(ScheduledRideService::class)->dispatch($scheduled->scheduledRide->id))->toBe('skipped')
        ->and(Ride::find($rideId)->status)->toBe(RideStatus::Cancelled);
});

it('keeps upcoming bookings out of the completion rate', function () {
    ($this->book)()->assertCreated();
    Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'status' => RideStatus::Completed,
    ]);

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/reports/completion-rate')
        ->assertOk()
        ->assertJsonPath('report.total_rides', 1)
        ->assertJsonPath('report.completion_rate', 100);
});

it('does not let anyone cancel with a system-only reason', function () {
    $rideId = ($this->book)()->json('ride.id');

    $this->withToken($this->token)->deleteJson("/api/v1/rides/{$rideId}/scheduled", [
        'reason' => 'scheduled_dispatch_failed',
        'reason_details' => 'Spoofed message',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});
