<?php

use App\Contracts\MapsGateway;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Driver;
use App\Models\GamificationProfile;
use App\Models\Notification;
use App\Models\PricingConfig;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\Ride;
use App\Models\SurgeRule;
use App\Models\TierConfig;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\RidePinService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Queue::fake();

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->approved()->create([
        'user_id' => $this->driverUser->id,
        'is_online' => true,
    ]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->admin = User::factory()->admin()->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN', 'timezone' => 'Africa/Lagos']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);
    Vehicle::factory()->create(['driver_id' => $this->driver->id, 'vehicle_class_id' => $this->vehicleClass->id]);

    // 600 base + 8.5 km x 250 + 15 min x 40 = 3,325.00 metered fare.
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
    $locations->shouldReceive('getDriverLocation')->andReturnNull();
    $this->app->instance(DriverLocationService::class, $locations);
});

/**
 * Sanctum's guard caches the resolved user for the whole test, so switch the
 * acting user explicitly whenever a test sends requests as different people.
 */
function actingWith(object $test, string $token): object
{
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

function bookRide(object $test, array $overrides = []): TestResponse
{
    return actingWith($test, $test->passengerToken)->postJson('/api/v1/rides', array_merge([
        'city_id' => $test->city->id,
        'vehicle_class_id' => $test->vehicleClass->id,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'pickup_address' => '123 Test Street',
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'destination_address' => '456 Dest Street',
        'payment_method' => 'cash',
    ], $overrides));
}

/**
 * Puts the ride in progress as if it started $tripMinutes ago, so completing
 * it now bills that much trip time. The default matches the mocked route
 * estimate, keeping the final fare equal to the booking quote.
 */
function startTrip(object $test, Ride $ride, int $tripMinutes = 15): Ride
{
    $ride->update([
        'driver_id' => $test->driverUser->id,
        'status' => RideStatus::InProgress,
        'matched_at' => now()->subMinutes($tripMinutes),
        'started_at' => now()->subMinutes($tripMinutes),
    ]);

    return $ride->fresh();
}

function completeTrip(object $test, Ride $ride): TestResponse
{
    return actingWith($test, $test->driverToken)->postJson("/api/v1/rides/{$ride->id}/complete");
}

// === Booking validation ===

it('rejects booking with 422 when the vehicle class has no pricing in the city', function () {
    $unpricedClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($unpricedClass->id, ['is_active' => true]);

    bookRide($this, ['vehicle_class_id' => $unpricedClass->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'vehicle_class_id' => 'Pricing is not yet configured for this vehicle class in the selected city.',
        ]);

    expect(Ride::count())->toBe(0);
});

it('rejects booking with 422 when the vehicle class is deactivated platform-wide', function () {
    $this->vehicleClass->update(['is_active' => false]);

    bookRide($this)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'vehicle_class_id' => 'This vehicle class is not available in the selected city.',
        ]);
});

it('gives each booking its own trip reference even when two are saved at the same moment', function () {
    $referenceDrawnByOtherBooking = Ride::generateReferenceNumber();

    $reference = bookRide($this)->assertCreated()->json('ride.reference_number');

    expect($reference)->toStartWith('TRP-NG-')->not->toBe($referenceDrawnByOtherBooking);
});

// === Cancellation ===

it('lets a passenger dismiss a ride that ended with no driver found', function () {
    $ride = Ride::factory()->noDriverFound()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    actingWith($this, $this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/cancel", ['reason' => 'changed_mind'])
        ->assertOk()
        ->assertJsonPath('ride.status', 'cancelled');

    expect($ride->fresh()->status)->toBe(RideStatus::Cancelled);
});

it('tells the driver that E-tiGo support cancelled the ride when an admin cancels it', function () {
    $ride = Ride::factory()->driverEnRoute()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    actingWith($this, $this->adminToken)
        ->postJson("/api/v1/rides/{$ride->id}/cancel", ['reason' => 'safety_concern'])
        ->assertOk();

    $driverNotification = Notification::where('user_id', $this->driverUser->id)
        ->where('type', 'ride_cancelled')
        ->first();

    expect($driverNotification->body)->toBe('E-tiGo support cancelled the ride. Reason: Safety concern.');
});

// === Ride listing ===

it('rejects a non-UUID city filter on the ride list with 422', function () {
    actingWith($this, $this->passengerToken)
        ->getJson('/api/v1/rides?city_id=not-a-uuid')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id']);
});

it('never disables pagination when per_page is negative', function () {
    Ride::factory()->count(3)->create(['passenger_id' => $this->passenger->id]);

    actingWith($this, $this->passengerToken)
        ->getJson('/api/v1/rides?per_page=-1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonCount(1, 'rides');
});

// === Ride PIN ===

it('keeps the ride PIN valid when the pickup happens hours after booking', function () {
    $booking = bookRide($this)->assertCreated();
    $ride = Ride::find($booking->json('ride.id'));

    $this->travel(2)->hours();
    $ride->update(['driver_id' => $this->driverUser->id, 'status' => RideStatus::DriverArrived, 'matched_at' => now()]);

    actingWith($this, $this->driverToken)
        ->postJson("/api/v1/rides/{$ride->id}/verify-pin", ['pin_code' => $booking->json('pin_code')])
        ->assertOk()
        ->assertJsonPath('ride.status', 'in_progress');
});

it('issues a fresh PIN to the passenger after the driver exhausts the attempts', function () {
    $ride = Ride::factory()->driverArrived()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);
    $originalPin = app(RidePinService::class)->generatePin($ride)['pin_code'];
    $wrongPin = $originalPin === '0000' ? '1111' : '0000';
    foreach (range(1, 3) as $attempt) {
        actingWith($this, $this->driverToken)->postJson("/api/v1/rides/{$ride->id}/verify-pin", ['pin_code' => $wrongPin]);
    }

    $newPin = actingWith($this, $this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/pin/regenerate")
        ->assertOk()
        ->json('pin_code');

    expect($newPin)->toMatch('/^\d{4}$/');
    actingWith($this, $this->driverToken)
        ->postJson("/api/v1/rides/{$ride->id}/verify-pin", ['pin_code' => $newPin])
        ->assertOk()
        ->assertJsonPath('verified', true);
});

it('forbids regenerating the PIN of another passenger\'s ride', function () {
    $ride = Ride::factory()->driverArrived()->create([
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    actingWith($this, $this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/pin/regenerate")
        ->assertForbidden();
});

it('refuses to regenerate the PIN once the trip has started', function () {
    $ride = Ride::factory()->inProgress()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    actingWith($this, $this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/pin/regenerate")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A new PIN can only be issued before the trip starts.');
});

it('explains that a new PIN needs an assigned driver while the ride is still searching', function () {
    $ride = Ride::factory()->searching()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
    ]);

    actingWith($this, $this->passengerToken)
        ->postJson("/api/v1/rides/{$ride->id}/pin/regenerate")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A new PIN can be issued once a driver has been assigned.');
});

// === Location privacy and share links ===

it('stops sharing the driver\'s live location once the ride is completed', function () {
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
    ]);

    actingWith($this, $this->passengerToken)
        ->getJson("/api/v1/rides/{$ride->id}/location")
        ->assertOk()
        ->assertJsonPath('location', null)
        ->assertJsonPath('message', 'Live location is only available during an active ride.');
});

it('returns 404 for a share link with a malformed ride id', function () {
    $this->getJson('/api/v1/rides/not-a-ride/share/some-token')
        ->assertNotFound()
        ->assertJsonPath('message', 'Invalid or expired share link.');
});

// === Final fare: surge and tier discount locked in at booking ===

it('charges the surge multiplier quoted at booking even after the surge ends', function () {
    $surge = SurgeRule::factory()->manual()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => null,
        'multiplier' => 1.5,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $booking = bookRide($this)->assertCreated()->assertJsonPath('ride.fare_estimate_amount', '4987.50');
    $surge->update(['is_active' => false]);
    $ride = startTrip($this, Ride::find($booking->json('ride.id')));

    completeTrip($this, $ride)->assertOk();

    expect($ride->fresh()->final_fare_amount)->toBe('4987.50')
        ->and($ride->fresh()->pricing_snapshot['surge_multiplier'])->toBe(1.5);
});

it('applies the passenger\'s tier booking-fee discount to the estimate and the final fare', function () {
    TierConfig::updateOrCreate(['tier_level' => 3], [
        'tier_name' => 'Gold', 'min_points_required' => 2000, 'booking_fee_discount_pct' => 5,
        'ev_reservation_fee_waived' => false, 'priority_matching_enabled' => true,
    ]);
    GamificationProfile::create([
        'user_id' => $this->passenger->id, 'total_carbon_score' => 0,
        'total_ranking_points' => 2500, 'current_tier' => 3,
    ]);

    actingWith($this, $this->passengerToken)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->city->id,
            'pickup_lat' => 6.5244, 'pickup_lng' => 3.3792,
            'destination_lat' => 6.4541, 'destination_lng' => 3.3947,
        ])
        ->assertOk()
        ->assertJsonPath('estimates.0.fare_estimate', '3295.00')
        ->assertJsonPath('estimates.0.booking_fee_discount', 30);

    $booking = bookRide($this)->assertCreated();
    $ride = startTrip($this, Ride::find($booking->json('ride.id')));
    completeTrip($this, $ride)->assertOk();

    expect($ride->fresh()->final_fare_amount)->toBe('3295.00')
        ->and($ride->fresh()->pricing_snapshot['tier_discount'])->toEqual([
            'tier' => 'Gold', 'percentage' => 5, 'amount' => 30,
        ]);
});

// === Final fare: upfront fare and trip time ===
// The mocked route quotes 8.5 km and 15 min: 600 + 8.5 × 250 + 15 × 40 = 3,325.
// Tolerance is max(25% of 15, 10) = 10 min; the billed-time cap is 3 × 15 = 45 min.

it('charges the upfront quote when the trip runs within the time tolerance', function () {
    $booking = bookRide($this)->assertCreated()->assertJsonPath('ride.fare_estimate_amount', '3325.00');
    $ride = startTrip($this, Ride::find($booking->json('ride.id')), tripMinutes: 24);
    completeTrip($this, $ride)->assertOk();

    $snapshot = $ride->fresh()->pricing_snapshot;
    expect($ride->fresh()->final_fare_amount)->toBe('3325.00')
        ->and($snapshot['duration_minutes'])->toEqual(15)
        ->and($snapshot['actual_trip_minutes'])->toEqual(24)
        ->and($snapshot['time_adjusted'])->toBeFalse();
});

it('keeps the upfront quote when the trip is quicker than estimated', function () {
    $booking = bookRide($this)->assertCreated();
    $ride = startTrip($this, Ride::find($booking->json('ride.id')), tripMinutes: 8);
    completeTrip($this, $ride)->assertOk();

    expect($ride->fresh()->final_fare_amount)->toBe('3325.00');
});

it('bills the actual trip time when the trip runs significantly longer', function () {
    $booking = bookRide($this)->assertCreated();
    $ride = startTrip($this, Ride::find($booking->json('ride.id')), tripMinutes: 40);
    completeTrip($this, $ride)->assertOk();

    // 600 + 8.5 × 250 + 40 × 40
    $snapshot = $ride->fresh()->pricing_snapshot;
    expect($ride->fresh()->final_fare_amount)->toBe('4325.00')
        ->and($snapshot['duration_minutes'])->toEqual(40)
        ->and($snapshot['quoted_duration_minutes'])->toEqual(15)
        ->and($snapshot['time_adjusted'])->toBeTrue()
        ->and($snapshot['time_capped'])->toBeFalse();

    actingWith($this, $this->passengerToken)
        ->getJson("/api/v1/rides/{$ride->id}/receipt")
        ->assertOk()
        ->assertJsonPath('receipt.trip_time.quoted_minutes', 15)
        ->assertJsonPath('receipt.trip_time.billed_minutes', 40)
        ->assertJsonPath('receipt.trip_time.adjusted', true)
        ->assertJsonPath('receipt.fare_breakdown.time_charge', 1600);
});

it('caps the billed trip time when a trip is left running', function () {
    $booking = bookRide($this)->assertCreated();
    $ride = startTrip($this, Ride::find($booking->json('ride.id')), tripMinutes: 180);
    completeTrip($this, $ride)->assertOk();

    // 600 + 8.5 × 250 + 45 (capped) × 40
    $snapshot = $ride->fresh()->pricing_snapshot;
    expect($ride->fresh()->final_fare_amount)->toBe('4525.00')
        ->and($snapshot['duration_minutes'])->toEqual(45)
        ->and($snapshot['actual_trip_minutes'])->toEqual(180)
        ->and($snapshot['time_capped'])->toBeTrue();
});

it('uses the quoted route without asking the maps provider again', function () {
    $booking = bookRide($this)->assertCreated();
    $ride = startTrip($this, Ride::find($booking->json('ride.id')));

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldNotReceive('getDistanceAndDuration');
    $this->app->instance(MapsGateway::class, $maps);

    completeTrip($this, $ride)->assertOk();

    expect($ride->fresh()->final_fare_amount)->toBe('3325.00')
        ->and($ride->fresh()->pricing_snapshot['quoted_distance_km'])->toEqual(8.5);
});

// === Promos across the ride lifecycle ===

it('honours a first-ride promo when the ride completes', function () {
    $promo = PromoCode::factory()->flat(500)->newUsersOnly(0)->create(['code' => 'FIRSTRIDE']);

    $booking = bookRide($this, ['promo_code' => 'FIRSTRIDE'])->assertCreated();
    $ride = startTrip($this, Ride::find($booking->json('ride.id')));
    completeTrip($this, $ride)->assertOk();

    expect($ride->fresh()->final_fare_amount)->toBe('2825.00')
        ->and($ride->fresh()->discount_amount)->toBe('500.00');
    $this->assertDatabaseHas('promo_redemptions', ['promo_code_id' => $promo->id, 'ride_id' => $ride->id]);
});

it('rejects at booking a promo restricted to another city', function () {
    PromoCode::factory()->flat(500)->create(['code' => 'ABUJAONLY', 'city_id' => City::factory()->create()->id]);

    bookRide($this, ['promo_code' => 'ABUJAONLY'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['promo_code' => 'This promo code is not valid in your city.']);

    expect(Ride::count())->toBe(0);
});

it('honours a promo that expires while the trip is under way', function () {
    PromoCode::factory()->flat(500)->unlimited()->create([
        'code' => 'LASTCALL',
        'starts_at' => now()->subDay(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $booking = bookRide($this, ['promo_code' => 'LASTCALL'])->assertCreated();
    $this->travel(30)->minutes();
    $ride = startTrip($this, Ride::find($booking->json('ride.id')));
    completeTrip($this, $ride)->assertOk();

    expect($ride->fresh()->discount_amount)->toBe('500.00');
    expect(PromoRedemption::where('ride_id', $ride->id)->exists())->toBeTrue();
});
