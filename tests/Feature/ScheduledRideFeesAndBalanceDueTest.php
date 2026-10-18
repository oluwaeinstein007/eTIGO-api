<?php

use App\Contracts\MapsGateway;
use App\Contracts\PaymentGateway;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\HoldStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Jobs\ChargeScheduledRideCancellationFeeJob;
use App\Models\Account;
use App\Models\City;
use App\Models\CommissionConfig;
use App\Models\Dispute;
use App\Models\Driver;
use App\Models\Hold;
use App\Models\Notification;
use App\Models\PassengerBalanceDue;
use App\Models\Payment;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\ScheduledRide;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\DriverLocationService;
use App\Services\PassengerBalanceService;
use App\Services\PaymentService;
use App\Services\ScheduledRideCancellationFeeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * Client decisions of 16 Oct 2026: a late cancellation of a driver-reserved
 * booking within 30 minutes of the ride costs the economy base fare; what
 * cannot be collected (cash, or a wallet that cannot cover it) and wallet
 * fares the wallet could not cover become a balance due.
 */

beforeEach(function () {
    Queue::fake()->except([ChargeScheduledRideCancellationFeeJob::class]);
    $this->travelTo(Carbon::parse('2026-10-19 08:00:00', 'UTC'));

    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerToken = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;
    $this->admin = User::factory()->admin(AdminRole::Finance)->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN', 'timezone' => 'Africa/Lagos']);

    // The ride is booked in Comfort (base ₦600); the fee follows Economy (base ₦400).
    $this->comfort = VehicleClass::factory()->create(['name' => 'comfort']);
    $this->economy = VehicleClass::factory()->create(['name' => 'economy']);
    foreach ([[$this->comfort, 600], [$this->economy, 400]] as [$class, $base]) {
        $this->city->vehicleClasses()->attach($class->id, ['is_active' => true]);
        PricingConfig::factory()->create([
            'city_id' => $this->city->id,
            'vehicle_class_id' => $class->id,
            'base_fare' => $base,
            'per_km_rate' => 250,
            'per_minute_rate' => 40,
            'minimum_fare' => 1500,
            'effective_from' => now()->subDay(),
            'created_by_admin_id' => $this->admin->id,
        ]);
    }

    $maps = Mockery::mock(MapsGateway::class);
    $maps->shouldReceive('getDistanceAndDuration')->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $maps->shouldReceive('reverseGeocode')->andReturn(['address' => '123 Test Street, Lagos']);
    $this->app->instance(MapsGateway::class, $maps);

    $locations = Mockery::mock(DriverLocationService::class);
    $locations->shouldReceive('getDriverLocation')->andReturn(['lat' => 6.52, 'lng' => 3.38, 'heading' => 0, 'speed' => 0, 'timestamp' => now()->timestamp]);
    $locations->shouldReceive('findNearbyDrivers')->andReturn([]);
    $this->app->instance(DriverLocationService::class, $locations);

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->driverUser->id, 'city_id' => $this->city->id, 'is_online' => true]);
    Vehicle::factory()->create(['driver_id' => $this->driver->id, 'vehicle_class_id' => $this->comfort->id]);
    $this->driverToken = $this->driverUser->createToken('auth', ['driver'])->plainTextToken;

    $this->as = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    };

    $this->wallet = fn (int $kobo) => Account::factory()->passengerWallet()->withBalance($kobo)->create([
        'owner_type' => (new User)->getMorphClass(),
        'owner_id' => $this->passenger->id,
    ]);

    $this->book = fn (array $overrides = []) => ($this->as)($this->passengerToken)->postJson('/api/v1/rides', [
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->comfort->id,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'pickup_address' => '123 Test Street',
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
        'destination_address' => '456 Dest Street',
        'payment_method' => 'cash',
        'scheduled_pickup_at' => now()->addMinutes(90)->toIso8601String(),
        ...$overrides,
    ]);

    // Book, have the driver reserve it, then move to $minutesBefore pickup.
    $this->reservedBooking = function (array $overrides = [], int $minutesBefore = 25) {
        $rideId = ($this->book)($overrides)->assertCreated()->json('ride.id');
        ($this->as)($this->driverToken)->postJson("/api/v1/driver/scheduled-rides/{$rideId}/reserve")->assertOk();
        $this->travelTo(ScheduledRide::where('ride_id', $rideId)->value('scheduled_pickup_at')->subMinutes($minutesBefore));

        return $rideId;
    };

    $this->cancel = fn (string $rideId, array $body = []) => ($this->as)($this->passengerToken)
        ->deleteJson("/api/v1/rides/{$rideId}/scheduled", $body);

    $this->driverEarnings = fn () => (int) Account::where('owner_id', $this->driver->id)
        ->where('type', AccountType::DriverEarningsAvailable)->value('balance');
});

// === LATE-CANCELLATION FEE ===

it('charges the economy base fare only within 30 minutes of a reserved ride', function () {
    $wallet = ($this->wallet)(1_000_000);
    $rideId = ($this->reservedBooking)(['payment_method' => 'wallet'], minutesBefore: 35);

    ($this->as)($this->passengerToken)->getJson("/api/v1/rides/{$rideId}")
        ->assertJsonPath('ride.scheduled.cancellation_fee_if_cancelled_now', 0);

    $this->travel(6)->minutes();
    ($this->as)($this->passengerToken)->getJson("/api/v1/rides/{$rideId}")
        ->assertJsonPath('ride.scheduled.cancellation_fee_if_cancelled_now', 400);

    ($this->cancel)($rideId)->assertOk()
        ->assertJsonPath('ride.scheduled.late_cancellation_fee', 400)
        ->assertJsonPath('ride.scheduled.late_cancellation_fee_status', 'charged');

    expect($wallet->fresh()->balance)->toBe(1_000_000 - 40_000)
        ->and(($this->driverEarnings)())->toBe(40_000)
        ->and(PassengerBalanceDue::count())->toBe(0);
});

it('falls back to the quoted base fare when the city has no economy pricing', function () {
    PricingConfig::where('vehicle_class_id', $this->economy->id)->delete();
    $rideId = ($this->reservedBooking)();

    ($this->as)($this->passengerToken)->getJson("/api/v1/rides/{$rideId}")
        ->assertJsonPath('ride.scheduled.cancellation_fee_if_cancelled_now', 600);
});

it('records a balance due for a cash booking and blocks booking until it is paid', function () {
    $rideId = ($this->reservedBooking)();

    ($this->cancel)($rideId)->assertOk()
        ->assertJsonPath('ride.scheduled.late_cancellation_fee_status', 'balance_due');

    $balance = PassengerBalanceDue::sole();
    expect($balance->reason->value)->toBe('late_cancellation_fee')
        ->and((float) $balance->amount)->toBe(400.0)
        ->and($balance->payee_driver_id)->toBe($this->driverUser->id)
        ->and(Notification::where('user_id', $this->passenger->id)->where('type', 'balance_due')->exists())->toBeTrue();

    ($this->book)(['scheduled_pickup_at' => null])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'BALANCE_DUE')
        ->assertJsonPath('balance_due', 400);

    ($this->as)($this->passengerToken)->getJson('/api/v1/passenger/balance-due')
        ->assertOk()
        ->assertJsonPath('total', 400)
        ->assertJsonPath('blocks_booking', true)
        ->assertJsonPath('items.0.reason', 'late_cancellation_fee');

    // Paying needs money in the wallet.
    ($this->as)($this->passengerToken)->postJson('/api/v1/passenger/balance-due/pay', ['payment_method' => 'wallet'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method');

    ($this->wallet)(100_000);
    ($this->as)($this->passengerToken)->postJson('/api/v1/passenger/balance-due/pay', ['payment_method' => 'wallet'])
        ->assertOk()
        ->assertJsonPath('total', 0);

    expect($balance->fresh()->status->value)->toBe('paid')
        ->and(($this->driverEarnings)())->toBe(40_000)
        ->and(ScheduledRide::where('ride_id', $rideId)->value('late_cancellation_fee_status'))->toBe('charged');

    ($this->book)(['scheduled_pickup_at' => null])->assertCreated();
});

it('turns a declined card or a short wallet into a balance due', function () {
    UserPaymentMethod::create(['user_id' => $this->passenger->id, 'gateway_token' => 'tok', 'card_brand' => 'VISA', 'card_last_four' => '4242', 'is_default' => true]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()->andReturn(['status' => 'failed', 'transaction_id' => 'txn_x', 'amount' => 400]);
    $this->app->instance(PaymentGateway::class, $gateway);

    $card = ($this->reservedBooking)(['payment_method' => 'card']);
    ($this->cancel)($card)->assertOk();

    expect(ScheduledRide::where('ride_id', $card)->value('late_cancellation_fee_status'))->toBe('balance_due')
        ->and(PassengerBalanceDue::where('ride_id', $card)->exists())->toBeTrue();
});

it('leaves an unknown card outcome for finance instead of charging twice', function () {
    UserPaymentMethod::create(['user_id' => $this->passenger->id, 'gateway_token' => 'tok', 'card_brand' => 'VISA', 'card_last_four' => '4242', 'is_default' => true]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()->andThrow(new RuntimeException('timeout'));
    $this->app->instance(PaymentGateway::class, $gateway);

    $rideId = ($this->reservedBooking)(['payment_method' => 'card']);
    ($this->cancel)($rideId)->assertOk();

    expect(ScheduledRide::where('ride_id', $rideId)->value('late_cancellation_fee_status'))->toBe('failed')
        ->and(PassengerBalanceDue::count())->toBe(0);
});

it('charges no fee for unreserved bookings, exempt reasons or operations cancelling', function () {
    $unreserved = ($this->book)(['scheduled_pickup_at' => now()->addMinutes(50)->toIso8601String()])->json('ride.id');
    $reserved = ($this->reservedBooking)(['scheduled_pickup_at' => now()->addMinutes(150)->toIso8601String()]);

    ($this->cancel)($unreserved)->assertOk();
    ($this->cancel)($reserved, ['reason' => 'safety_concern'])->assertOk();

    expect(ScheduledRide::whereNotNull('late_cancellation_fee')->count())->toBe(0)
        ->and(PassengerBalanceDue::count())->toBe(0);
});

// === WALLET SHORTFALL ===

it('records the part of a wallet fare the wallet could not cover and pays the driver when settled', function () {
    $wallet = ($this->wallet)(100_000);
    $ride = Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->comfort->id,
        'status' => RideStatus::Completed,
        'payment_method' => PaymentMethod::Wallet,
        'fare_estimate_amount' => 1000,
        'final_fare_amount' => 3000,
    ]);
    Hold::create(['account_id' => $wallet->id, 'ride_id' => $ride->id, 'amount' => 100_000, 'status' => HoldStatus::Active, 'expires_at' => now()->addHours(4), 'created_at' => now()]);

    app(PaymentService::class)->processWalletPayment($ride);

    $balance = PassengerBalanceDue::sole();
    expect($balance->reason->value)->toBe('wallet_shortfall')
        ->and((float) $balance->amount)->toBe(2000.0)
        ->and($balance->payee_driver_id)->toBe($this->driverUser->id)
        ->and(Payment::where('ride_id', $ride->id)->value('status'))->toBe(PaymentStatus::Failed);

    // ₦1,000 charged from the wallet less 20% commission.
    expect(($this->driverEarnings)())->toBe(80_000);

    UserPaymentMethod::create(['user_id' => $this->passenger->id, 'gateway_token' => 'tok', 'card_brand' => 'VISA', 'card_last_four' => '4242', 'is_default' => true]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()->with(Mockery::on(fn ($data) => (float) $data['amount'] === 2000.0))
        ->andReturn(['status' => 'successful', 'transaction_id' => 'txn_bal', 'amount' => 2000]);
    $this->app->instance(PaymentGateway::class, $gateway);

    ($this->as)($this->passengerToken)->postJson('/api/v1/passenger/balance-due/pay', ['payment_method' => 'card'])
        ->assertOk()
        ->assertJsonPath('paid.0.paid_via', 'card');

    // The remaining ₦2,000 less 20% commission reaches the driver; the fare is now paid.
    expect(($this->driverEarnings)())->toBe(80_000 + 160_000)
        ->and(Payment::where('ride_id', $ride->id)->value('status'))->toBe(PaymentStatus::Captured)
        ->and(Ride::find($ride->id)->payment_status)->toBe(PaymentStatus::Captured);
});

// === ADMIN AND SETTINGS ===

it('lets finance list and waive balances, and turn the booking block off', function () {
    $rideId = ($this->reservedBooking)();
    ($this->cancel)($rideId)->assertOk();
    $balance = PassengerBalanceDue::sole();

    ($this->as)($this->adminToken)->getJson('/api/v1/admin/balances-due?status=outstanding')
        ->assertOk()
        ->assertJsonCount(1, 'balances')
        ->assertJsonPath('summary.outstanding_total', 400);

    $support = User::factory()->admin(AdminRole::Support)->create();
    ($this->as)($support->createToken('auth', ['admin'])->plainTextToken)
        ->postJson("/api/v1/admin/balances-due/{$balance->id}/waive", ['reason' => 'Goodwill'])
        ->assertForbidden();

    ($this->as)($this->adminToken)->putJson('/api/v1/admin/settings/wallet', ['block_booking_with_balance_due' => false])->assertOk();
    ($this->book)(['scheduled_pickup_at' => null])->assertCreated();
    Ride::where('passenger_id', $this->passenger->id)->where('status', RideStatus::Searching)->update(['status' => RideStatus::Cancelled]);

    ($this->as)($this->adminToken)->postJson("/api/v1/admin/balances-due/{$balance->id}/waive", ['reason' => 'Goodwill'])
        ->assertOk()
        ->assertJsonPath('balance.status', 'waived');

    expect(ScheduledRide::where('ride_id', $rideId)->value('late_cancellation_fee_status'))->toBe('waived');
});

it('exposes the client defaults and lets operations change the fee basis', function () {
    $operations = User::factory()->admin(AdminRole::Operations)->create();
    $token = $operations->createToken('auth', ['admin'])->plainTextToken;

    ($this->as)($token)->getJson('/api/v1/admin/settings/scheduled-rides')
        ->assertJsonPath('settings.reservation_fee', 0)
        ->assertJsonPath('settings.late_cancellation_fee_basis', 'economy_base_fare')
        ->assertJsonPath('settings.late_cancellation_fee_vehicle_class', 'economy')
        ->assertJsonPath('settings.late_cancellation_window_minutes', 30)
        ->assertJsonPath('settings.min_lead_minutes', 30)
        ->assertJsonPath('settings.max_advance_days', 3);

    ($this->as)($token)->putJson('/api/v1/admin/settings/scheduled-rides', ['late_cancellation_fee_basis' => 'sometimes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('late_cancellation_fee_basis');

    ($this->as)($token)->putJson('/api/v1/admin/settings/scheduled-rides', ['late_cancellation_fee_basis' => 'flat', 'late_cancellation_fee' => 750])
        ->assertOk();

    $this->getJson('/api/v1/config')
        ->assertJsonPath('scheduled_rides.late_cancellation_fee_basis', 'flat')
        ->assertJsonPath('scheduled_rides.late_cancellation_fee', 750)
        ->assertJsonPath('scheduled_rides.late_cancellation_window_minutes', 30);
});

it('puts card money into the wallet when a balance was waived while the card was charged', function () {
    $rideId = ($this->reservedBooking)();
    ($this->cancel)($rideId)->assertOk();
    $balance = PassengerBalanceDue::sole();

    UserPaymentMethod::create(['user_id' => $this->passenger->id, 'gateway_token' => 'tok', 'card_brand' => 'VISA', 'card_last_four' => '4242', 'is_default' => true]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()->andReturnUsing(function () use ($balance) {
        // Finance waives it while the card charge is in flight.
        app(PassengerBalanceService::class)->waive($balance, $this->admin, 'Goodwill');

        return ['status' => 'successful', 'transaction_id' => 'txn_race', 'amount' => 400];
    });
    $this->app->instance(PaymentGateway::class, $gateway);

    ($this->as)($this->passengerToken)->postJson('/api/v1/passenger/balance-due/pay', ['payment_method' => 'card'])
        ->assertOk()
        ->assertJsonCount(0, 'paid');

    $wallet = Account::where('owner_id', $this->passenger->id)->where('type', AccountType::PassengerWallet)->sole();
    expect($wallet->balance)->toBe(40_000)
        ->and($balance->fresh()->status->value)->toBe('waived')
        ->and(($this->driverEarnings)())->toBe(0);
});

// === DISPUTING A LATE-CANCELLATION FEE (Phase 2 review) ===

it('lets the passenger dispute a charged fee and support refund it to the wallet from the driver', function () {
    $wallet = ($this->wallet)(1_000_000);
    $rideId = ($this->reservedBooking)(['payment_method' => 'wallet']);
    ($this->cancel)($rideId)->assertOk()->assertJsonPath('ride.scheduled.late_cancellation_fee_status', 'charged');

    // The driver cannot dispute the cancelled booking; the passenger can.
    ($this->as)($this->driverToken)->postJson("/api/v1/rides/{$rideId}/dispute", [
        'category' => 'fare_dispute',
        'description' => 'I should have been paid more for this.',
    ])->assertUnprocessable()->assertJsonValidationErrors('ride');

    $disputeId = ($this->as)($this->passengerToken)->postJson("/api/v1/rides/{$rideId}/dispute", [
        'category' => 'fare_dispute',
        'description' => 'The driver was nowhere near the pickup when I cancelled.',
    ])->assertCreated()->json('dispute.id');

    $agent = User::factory()->admin(AdminRole::Support)->create();

    // Card refunds and more than the fee are refused.
    $this->actingAs($agent)->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", [
        'status' => 'resolved',
        'resolution_notes' => 'Driver was far away; fee refunded.',
        'refund_amount' => 400,
        'refund_method' => 'card',
    ])->assertUnprocessable()->assertJsonValidationErrors('refund_amount');

    $this->actingAs($agent)->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", [
        'status' => 'resolved',
        'resolution_notes' => 'Driver was far away; fee refunded.',
        'refund_amount' => 500,
    ])->assertUnprocessable()->assertJsonValidationErrors('refund_amount');

    $this->actingAs($agent)->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", [
        'status' => 'resolved',
        'resolution_notes' => 'Driver was far away; fee refunded.',
        'refund_amount' => 400,
    ])->assertOk()
        ->assertJsonPath('dispute.status', 'resolved')
        ->assertJsonPath('dispute.refund_method', 'wallet');

    expect($wallet->fresh()->balance)->toBe(1_000_000)
        ->and(($this->driverEarnings)())->toBe(0)
        ->and((float) Dispute::findOrFail($disputeId)->driver_clawback_amount)->toBe(400.0)
        ->and(app(ScheduledRideCancellationFeeService::class)->refundableKobo(Ride::findOrFail($rideId)))->toBe(0);
});

it('waives an unpaid fee in full when a fee dispute is upheld', function () {
    $rideId = ($this->reservedBooking)();
    ($this->cancel)($rideId)->assertOk()->assertJsonPath('ride.scheduled.late_cancellation_fee_status', 'balance_due');

    $disputeId = ($this->as)($this->passengerToken)->postJson("/api/v1/rides/{$rideId}/dispute", [
        'category' => 'fare_dispute',
        'description' => 'I cancelled because the driver said he was not coming.',
    ])->assertCreated()->json('dispute.id');

    $agent = User::factory()->admin(AdminRole::Support)->create();

    // A balance due cannot be part-waived.
    $this->actingAs($agent)->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", [
        'status' => 'resolved',
        'resolution_notes' => 'Half of the fee waived as goodwill.',
        'refund_amount' => 200,
    ])->assertUnprocessable();

    // A waiver pays nothing back, so it is not recorded as a refund.
    $this->actingAs($agent)->postJson("/api/v1/admin/disputes/{$disputeId}/resolve", [
        'status' => 'resolved',
        'resolution_notes' => 'The driver cancelled on the passenger by phone.',
        'refund_amount' => 400,
    ])->assertOk()
        ->assertJsonPath('message', 'Dispute resolved successfully. The unpaid fee of NGN 400.00 was waived.')
        ->assertJsonPath('dispute.status', 'resolved')
        ->assertJsonPath('dispute.waived_amount', '400.00')
        ->assertJsonPath('dispute.refund_amount', null)
        ->assertJsonPath('dispute.refund_method', null);

    expect(PassengerBalanceDue::sole()->status->value)->toBe('waived')
        ->and(ScheduledRide::where('ride_id', $rideId)->value('late_cancellation_fee_status'))->toBe('waived')
        ->and(($this->driverEarnings)())->toBe(0);

    ($this->book)(['scheduled_pickup_at' => null])->assertCreated();
});

it('still refuses disputes on cancelled rides that carry no fee', function () {
    $rideId = ($this->book)()->assertCreated()->json('ride.id');
    ($this->cancel)($rideId)->assertOk();

    ($this->as)($this->passengerToken)->postJson("/api/v1/rides/{$rideId}/dispute", [
        'category' => 'fare_dispute',
        'description' => 'Nothing was charged but I want to complain.',
    ])->assertUnprocessable()->assertJsonValidationErrors('ride');
});
