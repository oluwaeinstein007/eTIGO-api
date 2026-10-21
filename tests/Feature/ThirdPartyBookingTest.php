<?php

use App\Contracts\MapsGateway;
use App\Contracts\SmsGateway;
use App\Enums\AdminRole;
use App\Enums\RiderNotificationStatus;
use App\Enums\RideStatus;
use App\Enums\ThirdPartyRecipient;
use App\Enums\UserType;
use App\Events\DriverLocationUpdated;
use App\Events\RiderNotificationUpdated;
use App\Jobs\SendThirdPartyRiderMessageJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\Notification;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\SosIncident;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\LogSmsGateway;
use App\Services\RidePinService;
use App\Services\RideService;
use App\Services\ScheduledRideService;
use App\Services\ThirdPartyBookingService;
use App\Services\ThirdPartyRoutingService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));

    $this->booker = User::factory()->create([
        'type' => UserType::Passenger,
        'first_name' => 'Tunde',
        'phone' => '+2348010000001',
    ]);
    $this->token = $this->booker->createToken('auth', ['passenger'])->plainTextToken;

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

    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturn(
        ['lat' => 6.53, 'lng' => 3.39, 'heading' => 90, 'speed' => 20, 'timestamp' => now()->timestamp],
    );
    $locations->shouldReceive('findNearbyDrivers')->andReturn([]);
    $this->app->instance(DriverLocationService::class, $locations);

    // Records every rider message; `channel` decides which channel accepts it.
    $this->sms = new class implements SmsGateway
    {
        public ?string $channel = 'whatsapp';

        /** @var list<array{phone: string, message: string}> */
        public array $sent = [];

        public function send(string $phone, string $message): bool
        {
            return $this->deliver($phone, $message) !== null;
        }

        public function deliver(string $phone, string $message): ?string
        {
            if ($this->channel !== null) {
                $this->sent[] = ['phone' => $phone, 'message' => $message];
            }

            return $this->channel;
        }

        public function sendOtp(string $phone, string $code, string $message): bool
        {
            return $this->send($phone, $message);
        }
    };
    $this->app->instance(SmsGateway::class, $this->sms);

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->book = function (array $overrides = []) {
        return ($this->as)($this->token)->postJson('/api/v1/rides', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'pickup_address' => '12 Allen Avenue, Ikeja',
            'destination_lat' => 6.4541,
            'destination_lng' => 3.3947,
            'destination_address' => '5 Bode Thomas Street, Surulere',
            'payment_method' => 'card',
            'rider_name' => 'Ada Okafor',
            'rider_phone' => '+2348020000002',
            'rider_age_confirmed' => true,
            ...$overrides,
        ]);
    };

    $this->setPolicy = function (array $values) {
        foreach ($values as $key => $value) {
            AppSetting::setValue("third_party.{$key}", is_bool($value) ? ($value ? 'true' : 'false') : $value, 'third_party');
        }
    };

    $this->makeDriver = function () {
        $user = User::factory()->create(['type' => UserType::Driver, 'first_name' => 'Bayo', 'phone' => '+2348030000003']);
        $driver = Driver::factory()->approved()->create(['user_id' => $user->id, 'city_id' => $this->city->id, 'is_online' => true]);
        Vehicle::factory()->create([
            'driver_id' => $driver->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'make' => 'Toyota',
            'model' => 'Corolla',
            'colour' => 'Silver',
            'plate_number' => 'LND-123-XY',
        ]);

        return [$user, $user->createToken('auth', ['driver'])->plainTextToken];
    };

    // Book a third-party ride and have a driver accept it.
    $this->bookAndAssign = function (array $overrides = []) {
        $rideId = ($this->book)($overrides)->assertCreated()->json('ride.id');
        [$driverUser, $driverToken] = ($this->makeDriver)();
        $result = app(RideService::class)->acceptRide(Ride::find($rideId), $driverUser);
        expect($result['success'])->toBeTrue();

        return [Ride::find($rideId), $driverUser, $driverToken];
    };

    $this->deliver = fn (Ride $ride, string $type = ThirdPartyRoutingService::DRIVER_DETAILS) => app(ThirdPartyRoutingService::class)->send($ride->id, $type);
});

// === BOOKING ===

it('books a ride for someone else with the policy saved on the ride', function () {
    $response = ($this->book)();

    $response->assertCreated()
        ->assertJsonPath('ride.is_third_party', true)
        ->assertJsonPath('ride.third_party.rider_name', 'Ada Okafor')
        ->assertJsonPath('ride.third_party.rider_phone', '+2348020000002')
        ->assertJsonPath('ride.third_party.pin_recipient', 'both')
        ->assertJsonPath('ride.third_party.tracking_recipient', 'both')
        ->assertJsonPath('ride.third_party.rider_notification_status', 'pending')
        ->assertJsonPath('ride.third_party.booker_sees_pin', true)
        ->assertJsonPath('ride.rider.first_name', 'Ada');

    expect($response->json('pin_code'))->toMatch('/^\d{4}$/');

    $ride = Ride::find($response->json('ride.id'));
    expect($ride->passenger_id)->toBe($this->booker->id)
        ->and($ride->pin_recipient)->toBe(ThirdPartyRecipient::Both)
        ->and(AuditLog::where('auditable_id', $ride->id)->where('event', 'third_party_ride_booked')->exists())->toBeTrue();
});

it('treats a rider phone equal to the booker phone as a normal ride', function () {
    ($this->book)(['rider_phone' => '0801 000 0001', 'rider_age_confirmed' => false])
        ->assertCreated()
        ->assertJsonPath('ride.is_third_party', false)
        ->assertJsonMissingPath('ride.third_party');
});

it('stores a Nigerian local rider number in international format', function () {
    $rideId = ($this->book)(['rider_phone' => '0802-000-0002'])->assertCreated()->json('ride.id');

    expect(Ride::find($rideId)->rider_phone)->toBe('+2348020000002')
        ->and(ThirdPartyBookingService::normalizePhone('2348020000002'))->toBe('+2348020000002')
        ->and(ThirdPartyBookingService::normalizePhone('00448020000002'))->toBe('+448020000002');
});

it('requires the rider name and the age confirmation', function () {
    ($this->book)(['rider_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('rider_name');
    ($this->book)(['rider_age_confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('rider_age_confirmed');
    ($this->book)(['rider_phone' => 'not-a-number'])->assertUnprocessable()->assertJsonValidationErrors('rider_phone');
});

it('allows only wallet and card by default', function () {
    ($this->book)(['payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    ($this->book)(['payment_method' => 'bank_transfer'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

    ($this->setPolicy)(['allow_cash' => true]);

    ($this->book)(['payment_method' => 'cash'])->assertCreated();
});

it('holds the booker wallet for a third-party wallet ride', function () {
    Account::factory()->passengerWallet()->withBalance(1_000_000)->create([
        'owner_type' => (new User)->getMorphClass(),
        'owner_id' => $this->booker->id,
    ]);

    ($this->book)(['payment_method' => 'wallet'])->assertCreated()->assertJsonPath('ride.status', 'searching');
});

it('refuses new third-party bookings when the feature is off', function () {
    ($this->setPolicy)(['enabled' => false]);

    ($this->book)()->assertUnprocessable()->assertJsonValidationErrors('rider_phone');
});

it('lets the booker ride themselves while a third-party ride is active, and the reverse', function () {
    ($this->book)()->assertCreated();

    // Own ride alongside the third-party ride.
    ($this->book)(['rider_phone' => null, 'rider_name' => null])->assertCreated()->assertJsonPath('ride.is_third_party', false);

    // A second own ride is still refused.
    ($this->book)(['rider_phone' => null, 'rider_name' => null])->assertStatus(409);

    // And a second third-party ride is fine.
    ($this->book)(['rider_phone' => '+2348020000009'])->assertCreated();
});

it('limits active third-party rides per booker', function () {
    ($this->book)()->assertCreated();
    ($this->book)(['rider_phone' => '+2348020000003'])->assertCreated();

    ($this->book)(['rider_phone' => '+2348020000004'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rider_phone' => 'at most 2 rides for other people']);
});

it('limits the different rider numbers per booker in 24 hours, counting cancelled bookings', function () {
    ($this->setPolicy)(['max_rider_numbers_per_day' => 2, 'max_active_per_booker' => 10]);

    $first = ($this->book)()->assertCreated()->json('ride.id');
    Ride::whereKey($first)->update(['status' => RideStatus::Cancelled]);
    ($this->book)(['rider_phone' => '+2348020000003'])->assertCreated();

    ($this->book)(['rider_phone' => '+2348020000004'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rider_phone' => '2 different people']);

    // A number already used today is not a new one.
    ($this->book)()->assertCreated();

    $this->travel(25)->hours();
    ($this->book)(['rider_phone' => '+2348020000004'])->assertCreated();
});

it('keeps the recipients a ride was booked with when the policy changes', function () {
    $rideId = ($this->book)()->assertCreated()->json('ride.id');

    ($this->setPolicy)(['pin_recipient' => 'rider', 'tracking_recipient' => 'booker']);

    $ride = Ride::find($rideId);
    expect($ride->pin_recipient)->toBe(ThirdPartyRecipient::Both)
        ->and($ride->tracking_recipient)->toBe(ThirdPartyRecipient::Both);

    $next = Ride::find(($this->book)(['rider_phone' => '+2348020000005'])->json('ride.id'));
    expect($next->pin_recipient)->toBe(ThirdPartyRecipient::Rider)
        ->and($next->tracking_recipient)->toBe(ThirdPartyRecipient::Booker);
});

// === PIN AND TRACKING ROUTING ===

it('sends the PIN to the rider only when the booker chooses it, and keeps the booker tracking', function () {
    $response = ($this->book)(['share_with' => 'rider'])->assertCreated()
        ->assertJsonPath('pin_code', null)
        ->assertJsonPath('ride.third_party.pin_recipient', 'rider')
        ->assertJsonPath('ride.third_party.tracking_recipient', 'both')
        ->assertJsonPath('ride.third_party.booker_sees_pin', false)
        ->assertJsonPath('ride.third_party.booker_sees_tracking', true)
        ->assertJsonPath('ride.third_party.rider_notification_status', 'pending');

    $audit = AuditLog::where('auditable_id', $response->json('ride.id'))->where('event', 'third_party_ride_booked')->first();
    expect($audit->new_values['share_with'])->toBe('rider');
});

it('lets the booker choice override the admin default', function () {
    ($this->setPolicy)(['pin_recipient' => 'rider', 'tracking_recipient' => 'rider']);

    ($this->book)(['share_with' => 'both'])->assertCreated()
        ->assertJsonPath('ride.third_party.pin_recipient', 'both')
        ->assertJsonPath('ride.third_party.tracking_recipient', 'both')
        ->assertJsonPath('ride.third_party.booker_sees_pin', true);

    // No choice sent (older app builds): the admin default still applies.
    ($this->book)(['rider_phone' => '+2348020000009'])->assertCreated()
        ->assertJsonPath('ride.third_party.pin_recipient', 'rider');
});

it('keeps the choice on a ride booked for later', function () {
    $rideId = ($this->book)([
        'share_with' => 'rider',
        'scheduled_pickup_at' => now()->addHours(3)->toIso8601String(),
    ])->assertCreated()->json('ride.id');

    expect(Ride::find($rideId))
        ->pin_recipient->toBe(ThirdPartyRecipient::Rider)
        ->tracking_recipient->toBe(ThirdPartyRecipient::Both);
});

it('does not offer the choice when operations send PINs to bookers only', function () {
    ($this->setPolicy)(['pin_recipient' => 'booker', 'tracking_recipient' => 'booker']);

    $this->getJson('/api/v1/config')
        ->assertJsonPath('third_party_bookings.share_with_options', [])
        ->assertJsonPath('third_party_bookings.default_share_with', null);

    ($this->book)(['share_with' => 'rider'])->assertCreated()
        ->assertJsonPath('ride.third_party.pin_recipient', 'booker')
        ->assertJsonPath('ride.third_party.tracking_recipient', 'booker')
        ->assertJsonPath('ride.third_party.rider_notification_status', null);
});

it('pre-selects rider only when that is the admin PIN default', function () {
    ($this->setPolicy)(['pin_recipient' => 'rider']);

    $this->getJson('/api/v1/config')
        ->assertJsonPath('third_party_bookings.share_with_options', ['rider', 'both'])
        ->assertJsonPath('third_party_bookings.default_share_with', 'rider');
});

it('only accepts rider or both as the choice', function (string $value) {
    ($this->book)(['share_with' => $value])->assertUnprocessable()->assertJsonValidationErrors('share_with');
})->with(['booker', 'everyone']);

it('ignores the choice on the booker\'s own ride', function () {
    ($this->book)(['share_with' => 'rider', 'rider_phone' => null, 'rider_name' => null])
        ->assertCreated()
        ->assertJsonPath('ride.is_third_party', false)
        ->assertJsonPath('pin_code', fn ($pin) => preg_match('/^\d{4}$/', (string) $pin) === 1);
});

it('hides the PIN from the booker when the policy sends it to the rider only', function () {
    ($this->setPolicy)(['pin_recipient' => 'rider']);

    $response = ($this->book)()->assertCreated()
        ->assertJsonPath('pin_code', null)
        ->assertJsonPath('ride.third_party.booker_sees_pin', false);

    ($this->as)($this->token)->getJson('/api/v1/rides/'.$response->json('ride.id'))
        ->assertOk()
        ->assertJsonPath('ride.pin_code', null);
});

it('messages the rider when a driver is assigned', function () {
    Event::fake([RiderNotificationUpdated::class]);

    [$ride] = ($this->bookAndAssign)();

    Queue::assertPushed(SendThirdPartyRiderMessageJob::class, fn ($job) => $job->rideId === $ride->id && $job->type === ThirdPartyRoutingService::DRIVER_DETAILS);

    ($this->deliver)($ride);

    $pin = app(RidePinService::class)->getCachedPin($ride);
    $message = $this->sms->sent[0]['message'];

    expect($this->sms->sent[0]['phone'])->toBe('+2348020000002')
        ->and($message)->toContain('Tunde booked a ride for you')
        ->and($message)->toContain('Bayo, silver Toyota Corolla (LND-123-XY)')
        ->and($message)->toContain("Your ride PIN: {$pin}")
        ->and($message)->toContain("/track/{$ride->id}/{$ride->share_token}")
        ->and($message)->toContain('Emergency: 112');

    $ride->refresh();
    expect($ride->rider_notification_status)->toBe(RiderNotificationStatus::SentWhatsapp)
        ->and($ride->rider_notification_channel)->toBe('whatsapp')
        ->and($ride->rider_notified_at)->not->toBeNull();

    Event::assertDispatched(RiderNotificationUpdated::class, fn ($event) => $event->rideId === $ride->id
        && $event->status === 'sent_whatsapp'
        && $event->broadcastOn()[0]->name === "private-user.{$this->booker->id}");
});

it('records an SMS fallback', function () {
    [$ride] = ($this->bookAndAssign)();
    $this->sms->channel = 'sms';

    ($this->deliver)($ride);

    expect($ride->fresh()->rider_notification_status)->toBe(RiderNotificationStatus::SentSms);
});

it('tells the rider to ask the booker for the PIN when the booker alone gets it', function () {
    ($this->setPolicy)(['pin_recipient' => 'booker']);
    [$ride] = ($this->bookAndAssign)();

    ($this->deliver)($ride);

    $pin = app(RidePinService::class)->getCachedPin($ride);
    expect($this->sms->sent[0]['message'])->toContain('Ask Tunde for your ride PIN')
        ->and($this->sms->sent[0]['message'])->not->toContain($pin);
});

it('sends the rider nothing when the policy is booker only', function () {
    ($this->setPolicy)(['pin_recipient' => 'booker', 'tracking_recipient' => 'booker']);

    ($this->bookAndAssign)();

    Queue::assertNotPushed(SendThirdPartyRiderMessageJob::class);
});

it('shows the booker the PIN and alerts them when the rider cannot be reached', function () {
    ($this->setPolicy)(['pin_recipient' => 'rider', 'tracking_recipient' => 'rider']);
    [$ride] = ($this->bookAndAssign)();
    $this->sms->channel = null;

    expect(fn () => ($this->deliver)($ride))->toThrow(RuntimeException::class);
    expect($ride->fresh()->rider_notification_status)->toBe(RiderNotificationStatus::Pending);

    // The job runs out of retries.
    (new SendThirdPartyRiderMessageJob($ride->id, ThirdPartyRoutingService::DRIVER_DETAILS))->failed(new RuntimeException('down'));

    $ride->refresh();
    expect($ride->rider_notification_status)->toBe(RiderNotificationStatus::Failed)
        ->and($ride->bookerSeesPin())->toBeTrue()
        ->and($ride->bookerSeesTracking())->toBeTrue()
        ->and(Notification::where('user_id', $this->booker->id)->where('type', 'third_party_rider_unreachable')->exists())->toBeTrue();

    ($this->as)($this->token)->getJson("/api/v1/rides/{$ride->id}")
        ->assertJsonPath('ride.pin_code', app(RidePinService::class)->getCachedPin($ride))
        ->assertJsonPath('ride.third_party.rider_notification_status', 'failed');
});

it('does not mark a ride failed once the trip has started', function () {
    [$ride] = ($this->bookAndAssign)();
    $ride->update(['status' => RideStatus::InProgress]);

    app(ThirdPartyRoutingService::class)->markFailed($ride->id, ThirdPartyRoutingService::DRIVER_DETAILS);

    expect($ride->fresh()->rider_notification_status)->toBe(RiderNotificationStatus::Pending);
});

it('sends a regenerated PIN to the rider again', function () {
    [$ride] = ($this->bookAndAssign)();
    ($this->deliver)($ride);
    $ride->update(['status' => RideStatus::DriverArrived]);

    $response = ($this->as)($this->token)->postJson("/api/v1/rides/{$ride->id}/pin/regenerate")
        ->assertOk()
        ->assertJsonPath('message', 'A new PIN has been issued and sent to Ada.')
        ->assertJsonPath('rider_notification_status', 'pending');

    Queue::assertPushed(SendThirdPartyRiderMessageJob::class, fn ($job) => $job->type === ThirdPartyRoutingService::PIN_UPDATE);

    ($this->deliver)($ride, ThirdPartyRoutingService::PIN_UPDATE);

    expect(end($this->sms->sent)['message'])->toContain("your new ride PIN is {$response->json('pin_code')}")
        ->and($ride->fresh()->rider_notification_status)->toBe(RiderNotificationStatus::SentWhatsapp);
});

it('keeps showing the booker the PIN when a resend follows a failed delivery', function () {
    ($this->setPolicy)(['pin_recipient' => 'rider']);
    [$ride] = ($this->bookAndAssign)();
    $ride->update(['rider_notification_status' => RiderNotificationStatus::Failed]);

    ($this->as)($this->token)->postJson("/api/v1/rides/{$ride->id}/pin/regenerate")
        ->assertOk()
        ->assertJsonPath('rider_notification_status', 'failed');

    expect(($this->as)($this->token)->getJson("/api/v1/rides/{$ride->id}")->json('ride.pin_code'))->toMatch('/^\d{4}$/');
});

it('tells the rider when the driver arrives and when the ride is cancelled after they were told', function () {
    [$ride, $driverUser] = ($this->bookAndAssign)();
    ($this->deliver)($ride);

    app(RideService::class)->driverArrived($ride->fresh(), $driverUser);
    Queue::assertPushed(SendThirdPartyRiderMessageJob::class, fn ($job) => $job->type === ThirdPartyRoutingService::DRIVER_ARRIVED);
    ($this->deliver)($ride, ThirdPartyRoutingService::DRIVER_ARRIVED);
    expect(end($this->sms->sent)['message'])->toContain('your driver Bayo has arrived in the silver Toyota Corolla (LND-123-XY)');

    app(RideService::class)->cancelRide($ride->fresh(), $this->booker, 'change_of_plans');
    Queue::assertPushed(SendThirdPartyRiderMessageJob::class, fn ($job) => $job->type === ThirdPartyRoutingService::CANCELLED);
    ($this->deliver)($ride, ThirdPartyRoutingService::CANCELLED);
    expect(end($this->sms->sent)['message'])->toContain('the ride Tunde booked for you from 12 Allen Avenue, Ikeja has been cancelled');
});

it('does not message the rider about a cancellation they never heard of', function () {
    $rideId = ($this->book)()->assertCreated()->json('ride.id');

    app(RideService::class)->cancelRide(Ride::find($rideId), $this->booker, 'change_of_plans');

    Queue::assertNotPushed(SendThirdPartyRiderMessageJob::class);
});

it('skips a driver-details message that is no longer relevant', function () {
    [$ride] = ($this->bookAndAssign)();
    $ride->update(['status' => RideStatus::Cancelled]);

    ($this->deliver)($ride);

    expect($this->sms->sent)->toBe([]);
});

// === WHO SEES WHAT ===

it('shows the driver the rider instead of the booker', function () {
    [$ride, , $driverToken] = ($this->bookAndAssign)();

    $response = ($this->as)($driverToken)->getJson("/api/v1/rides/{$ride->id}")->assertOk();

    $response->assertJsonPath('ride.rider.first_name', 'Ada')
        ->assertJsonPath('ride.rider.phone', '+2348020000002')
        ->assertJsonPath('ride.rider.is_third_party', true)
        ->assertJsonPath('ride.passenger.first_name', 'Tunde')
        ->assertJsonMissingPath('ride.passenger.phone')
        ->assertJsonMissingPath('ride.passenger.email')
        ->assertJsonMissingPath('ride.third_party')
        ->assertJsonMissingPath('ride.pin_code');

    // The rider's number is not shown to the driver after the trip.
    $ride->update(['status' => RideStatus::Completed, 'completed_at' => now()]);
    ($this->as)($driverToken)->getJson("/api/v1/rides/{$ride->id}")->assertJsonPath('ride.rider.phone', null);
});

it('keeps the live location from the booker when tracking goes to the rider only', function () {
    ($this->setPolicy)(['tracking_recipient' => 'rider']);
    [$ride] = ($this->bookAndAssign)();

    ($this->as)($this->token)->getJson("/api/v1/rides/{$ride->id}/location")->assertForbidden();
    ($this->as)($this->token)->getJson("/api/v1/rides/{$ride->id}")->assertJsonPath('ride.share_token', null);

    $ride->update(['rider_notification_status' => RiderNotificationStatus::Failed]);
    ($this->as)($this->token)->getJson("/api/v1/rides/{$ride->id}/location")->assertOk();
});

it('lists the booker own active ride apart from rides booked for others', function () {
    $thirdPartyId = ($this->book)()->json('ride.id');
    $ownId = ($this->book)(['rider_phone' => null, 'rider_name' => null])->json('ride.id');

    ($this->as)($this->token)->getJson('/api/v1/passenger/active-ride')
        ->assertOk()
        ->assertJsonPath('ride.id', $ownId)
        ->assertJsonCount(1, 'third_party_rides')
        ->assertJsonPath('third_party_rides.0.id', $thirdPartyId);

    ($this->as)($this->token)->getJson('/api/v1/rides?is_third_party=1')
        ->assertOk()
        ->assertJsonCount(1, 'rides');
});

// === TRACKING PAGE ===

it('serves the public share data the tracking page needs', function () {
    config(['sos.safety_line' => '+2348000000000']);
    [$ride] = ($this->bookAndAssign)();

    $this->getJson("/api/v1/rides/{$ride->id}/share/{$ride->share_token}")
        ->assertOk()
        ->assertJsonPath('ride.driver.first_name', 'Bayo')
        ->assertJsonPath('ride.driver.vehicle.plate_number', 'LND-123-XY')
        ->assertJsonPath('ride.rider_first_name', 'Ada')
        ->assertJsonPath('ride.booked_by_first_name', 'Tunde')
        ->assertJsonPath('driver_location.lat', 6.53)
        ->assertJsonPath('safety.emergency_number', '112')
        ->assertJsonPath('safety.safety_line', '+2348000000000')
        ->assertJsonMissingPath('ride.passenger');

    $this->get("/track/{$ride->id}/{$ride->share_token}")
        ->assertOk()
        ->assertSee('Track your E-tiGo ride')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get("/track/{$ride->id}/bad")->assertNotFound();
});

// === SCHEDULED THIRD-PARTY RIDES ===

it('reminds the rider of a scheduled ride and dispatches it while the booker is on their own ride', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])
        ->assertCreated()
        ->assertJsonPath('ride.status', 'scheduled')
        ->json('ride.id');
    $ride = Ride::with('scheduledRide')->find($rideId);

    $this->travelTo(now()->addHours(2)->addMinutes(1));
    app(ScheduledRideService::class)->sendReminder($ride->scheduledRide->id);

    Queue::assertPushed(SendThirdPartyRiderMessageJob::class, fn ($job) => $job->type === ThirdPartyRoutingService::REMINDER);
    ($this->deliver)($ride, ThirdPartyRoutingService::REMINDER);
    expect($this->sms->sent[0]['message'])->toContain('Tunde booked you a ride at 12:00 PM from 12 Allen Avenue, Ikeja')
        ->and($this->sms->sent[0]['message'])->not->toContain('PIN:');

    // The booker is on a ride of their own at dispatch time.
    Ride::factory()->inProgress()->create(['passenger_id' => $this->booker->id, 'city_id' => $this->city->id, 'vehicle_class_id' => $this->vehicleClass->id]);

    $this->travelTo($ride->scheduledRide->scheduled_pickup_at->copy()->subMinutes(10));
    expect(app(ScheduledRideService::class)->dispatch($ride->scheduledRide->id))->toBe('dispatched')
        ->and(app(RidePinService::class)->getCachedPin($ride))->not->toBeNull();
});

it('does not clash a scheduled ride for someone else with the booker own booking', function () {
    $at = now()->addHours(3)->toIso8601String();

    ($this->book)(['scheduled_pickup_at' => $at, 'rider_phone' => null, 'rider_name' => null])->assertCreated();
    ($this->book)(['scheduled_pickup_at' => $at])->assertCreated();
    ($this->book)(['scheduled_pickup_at' => $at])->assertUnprocessable()->assertJsonValidationErrors('scheduled_pickup_at');

    expect(ScheduledRide::count())->toBe(2);
});

it('applies the payment rules when a scheduled third-party booking is edited', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])->json('ride.id');

    ($this->as)($this->token)->putJson("/api/v1/rides/{$rideId}/scheduled", ['payment_method' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');
});

// === SAFETY ===

it('gives the Safety team the rider and the car position when the booker raises SOS', function () {
    [$ride] = ($this->bookAndAssign)();
    $ride->update(['status' => RideStatus::InProgress]);

    ($this->as)($this->token)->postJson("/api/v1/rides/{$ride->id}/sos", ['gps_lat' => 9.05, 'gps_lng' => 7.49])
        ->assertCreated();

    $incident = SosIncident::where('ride_id', $ride->id)->firstOrFail();
    expect((float) $incident->gps_lat)->toBe(6.53)
        ->and($incident->telemetry_data['third_party_rider'])->toBe(['name' => 'Ada Okafor', 'phone' => '+2348020000002'])
        ->and($incident->telemetry_data['triggered_by_booker'])->toBeTrue()
        ->and($incident->telemetry_data['gps_source'])->toBe('driver')
        ->and($incident->telemetry_data['reporter_gps'])->toBe(['lat' => 9.05, 'lng' => 7.49]);
});

// === ADMIN SETTINGS ===

it('lets operations view and change the policy, audit-logged', function () {
    ($this->as)($this->adminToken)->getJson('/api/v1/admin/settings/third-party')
        ->assertOk()
        ->assertJsonPath('settings.pin_recipient', 'both')
        ->assertJsonPath('settings.max_active_per_booker', 2)
        ->assertJsonPath('settings.allow_cash', false);

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/third-party', [
        'pin_recipient' => 'rider',
        'allow_cash' => true,
        'max_rider_numbers_per_day' => 5,
    ])->assertOk()
        ->assertJsonPath('settings.pin_recipient', 'rider')
        ->assertJsonPath('settings.allow_cash', true)
        ->assertJsonPath('settings.max_rider_numbers_per_day', 5);

    expect(AuditLog::where('event', 'third_party_settings.updated')->exists())->toBeTrue();

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/third-party', ['pin_recipient' => 'nobody'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin_recipient');
});

it('keeps the policy settings away from other admin roles', function () {
    $finance = User::factory()->admin(AdminRole::Finance)->create();

    ($this->as)($finance->createToken('auth', ['admin'])->plainTextToken)
        ->getJson('/api/v1/admin/settings/third-party')
        ->assertForbidden();
});

it('tells the apps the third-party rules in the app config', function () {
    $this->getJson('/api/v1/config')
        ->assertOk()
        ->assertJsonPath('third_party_bookings.enabled', true)
        ->assertJsonPath('third_party_bookings.payment_methods', ['wallet', 'card'])
        ->assertJsonPath('third_party_bookings.pin_recipient', 'both')
        ->assertJsonPath('third_party_bookings.share_with_options', ['rider', 'both'])
        ->assertJsonPath('third_party_bookings.default_share_with', 'both');

    ($this->setPolicy)(['allow_bank_transfer' => true, 'tracking_recipient' => 'rider']);

    $this->getJson('/api/v1/config')
        ->assertJsonPath('third_party_bookings.payment_methods', ['wallet', 'card', 'bank_transfer'])
        ->assertJsonPath('third_party_bookings.tracking_recipient', 'rider');
});

// === REVIEW FOLLOW-UPS ===

it('leaves the delivery status empty when the rider is never messaged', function () {
    ($this->setPolicy)(['pin_recipient' => 'booker', 'tracking_recipient' => 'booker']);

    ($this->book)()->assertCreated()->assertJsonPath('ride.third_party.rider_notification_status', null);
});

it('does not count a search past its deadline towards the third-party limit', function () {
    ($this->setPolicy)(['max_active_per_booker' => 1]);
    ($this->book)()->assertCreated();

    $this->travel(10)->minutes();

    ($this->book)(['rider_phone' => '+2348020000003'])->assertCreated();
    expect(Ride::where('passenger_id', $this->booker->id)->where('status', RideStatus::NoDriverFound)->count())->toBe(1);
});

it('tells the rider at once about a scheduled booking already inside the reminder window', function () {
    ($this->book)(['scheduled_pickup_at' => now()->addMinutes(45)->toIso8601String()])->assertCreated();

    Queue::assertPushed(SendThirdPartyRiderMessageJob::class, fn ($job) => $job->type === ThirdPartyRoutingService::REMINDER);
});

it('waits for the reminder when a scheduled booking is further ahead', function () {
    ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])->assertCreated();

    Queue::assertNotPushed(SendThirdPartyRiderMessageJob::class);
});

it('tells the rider about a new time when no reminder is still to come', function () {
    $rideId = ($this->book)(['scheduled_pickup_at' => now()->addHours(3)->toIso8601String()])->json('ride.id');
    Queue::assertNotPushed(SendThirdPartyRiderMessageJob::class);

    ($this->as)($this->token)->putJson("/api/v1/rides/{$rideId}/scheduled", [
        'scheduled_pickup_at' => now()->addMinutes(50)->toIso8601String(),
    ])->assertOk();

    Queue::assertPushed(SendThirdPartyRiderMessageJob::class, fn ($job) => $job->rideId === $rideId && $job->type === ThirdPartyRoutingService::REMINDER);
});

it('strips links from booker-entered text in rider messages', function () {
    [$ride] = ($this->bookAndAssign)(['pickup_address' => 'Gate 2 https://evil.example/login www.evil.example now']);

    ($this->deliver)($ride);

    expect($this->sms->sent[0]['message'])->toContain('from Gate 2 now.')
        ->and($this->sms->sent[0]['message'])->not->toContain('evil.example');
});

it('keeps the booker position when the car position is not fresh', function () {
    $stale = Mockery::mock(DriverLocationService::class);
    $stale->shouldReceive('getDriverLocation')->andReturn(['lat' => 6.53, 'lng' => 3.39, 'heading' => 0, 'speed' => 0, 'timestamp' => now()->subMinutes(10)->timestamp]);
    $stale->shouldReceive('findNearbyDrivers')->andReturn([]);
    $this->app->instance(DriverLocationService::class, $stale);

    [$ride] = ($this->bookAndAssign)();
    $ride->update(['status' => RideStatus::InProgress]);

    ($this->as)($this->token)->postJson("/api/v1/rides/{$ride->id}/sos", ['gps_lat' => 9, 'gps_lng' => 7])->assertCreated();

    $incident = SosIncident::where('ride_id', $ride->id)->firstOrFail();
    expect((float) $incident->gps_lat)->toBe(9.0)
        ->and($incident->telemetry_data['gps_source'])->toBe('reporter');
});

// === CODE REVIEW FOLLOW-UPS ===

it('keeps the third-party limit when a ride that found no driver is retried', function () {
    ($this->setPolicy)(['max_active_per_booker' => 1]);
    $firstId = ($this->book)()->json('ride.id');
    Ride::whereKey($firstId)->update(['status' => RideStatus::NoDriverFound]);
    ($this->book)(['rider_phone' => '+2348020000003'])->assertCreated();

    ($this->as)($this->token)->postJson("/api/v1/rides/{$firstId}/rebroadcast")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ride' => 'at most 1 rides for other people']);
});

it('leaves an old driver position out of the public tracking data', function () {
    $old = Mockery::mock(DriverLocationService::class);
    $old->shouldReceive('getDriverLocation')->andReturn(['lat' => 6.53, 'lng' => 3.39, 'heading' => 0, 'speed' => 0, 'timestamp' => now()->subMinutes(3)->timestamp]);
    $old->shouldReceive('findNearbyDrivers')->andReturn([]);
    $this->app->instance(DriverLocationService::class, $old);

    [$ride] = ($this->bookAndAssign)();

    $this->getJson("/api/v1/rides/{$ride->id}/share/{$ride->share_token}")
        ->assertOk()
        ->assertJsonPath('driver_location', null)
        ->assertJsonPath('eta', null);
});

it('reports no channel when there is no messaging provider', function () {
    expect((new LogSmsGateway)->deliver('+2348020000002', 'hello'))->toBeNull()
        ->and((new LogSmsGateway)->send('+2348020000002', 'hello'))->toBeTrue();
});

it('falls back at once when the rider message cannot be queued', function () {
    ($this->setPolicy)(['pin_recipient' => 'rider']);
    $rideId = ($this->book)()->json('ride.id');
    [$driverUser] = ($this->makeDriver)();

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    $this->app->instance(Dispatcher::class, $bus);

    app(RideService::class)->acceptRide(Ride::find($rideId), $driverUser);

    $ride = Ride::find($rideId);
    expect($ride->rider_notification_status)->toBe(RiderNotificationStatus::Failed)
        ->and($ride->bookerSeesPin())->toBeTrue();
});

it('issues a new PIN rather than sending the rider none', function () {
    [$ride] = ($this->bookAndAssign)();
    Cache::forget("ride:{$ride->id}:pin_code");

    ($this->deliver)($ride);

    $pin = app(RidePinService::class)->getCachedPin($ride);
    expect($pin)->not->toBeNull()
        ->and($this->sms->sent[0]['message'])->toContain("Your ride PIN: {$pin}");
});

it('keeps the booker on the ride channel but leaves the car position off it under rider-only tracking', function () {
    ($this->setPolicy)(['tracking_recipient' => 'rider']);
    [$ride] = ($this->bookAndAssign)();

    $location = ['lat' => 6.5, 'lng' => 3.4, 'heading' => 0.0, 'speed' => 0.0, 'timestamp' => now()->timestamp];
    $hidden = new DriverLocationUpdated('d1', $location, $ride->id, null, $ride->bookerSeesTracking());
    $shown = new DriverLocationUpdated('d1', $location, $ride->id, null, true);

    expect(collect($hidden->broadcastOn())->pluck('name')->all())->toBe(['private-admin.rides'])
        ->and(collect($shown->broadcastOn())->pluck('name')->all())->toContain("private-ride.{$ride->id}");
});

it('uses the configured emergency number', function () {
    config(['sos.emergency_number' => '199']);

    $this->getJson('/api/v1/config')->assertJsonPath('emergency_number', '199');
});
