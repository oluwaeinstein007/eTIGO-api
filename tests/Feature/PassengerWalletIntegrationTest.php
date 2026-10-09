<?php

use App\Contracts\MapsGateway;
use App\Enums\HoldStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RideStatus;
use App\Enums\UserType;
use App\Enums\WalletTransactionStatus;
use App\Jobs\ExpireAbandonedTopupsJob;
use App\Jobs\MatchingTimeoutJob;
use App\Models\Account;
use App\Models\City;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\Hold;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use App\Models\WalletTransaction;
use App\Services\DriverMatchingService;
use App\Services\PaymentService;
use App\Services\RideService;
use App\Services\RideStateMachine;
use App\Services\WalletPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    $mockMaps = Mockery::mock(MapsGateway::class);
    $mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $this->app->instance(MapsGateway::class, $mockMaps);

    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerAccount = Account::factory()->passengerWallet()->withBalance(1000000)->create([
        'owner_type' => (new User)->getMorphClass(),
        'owner_id' => $this->passenger->id,
    ]);
    $this->token = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->driverUser->id]);

    $this->city = City::factory()->create(['currency_code' => 'NGN']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    $admin = User::factory()->admin()->create();
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 600,
        'per_km_rate' => 250,
        'per_minute_rate' => 40,
        'minimum_fare' => 1500,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $admin->id,
    ]);
});

// --- BE-WAL-14: Wallet ride creation places hold ---

test('creating a wallet ride places a hold on the passenger wallet', function () {
    Queue::fake();

    $response = $this->withToken($this->token)->postJson('/api/v1/rides', [
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'pickup_lat' => 9.0579,
        'pickup_lng' => 7.4951,
        'pickup_address' => 'Wuse 2, Abuja',
        'destination_lat' => 9.0765,
        'destination_lng' => 7.3986,
        'destination_address' => 'Garki, Abuja',
        'payment_method' => 'wallet',
    ]);

    $response->assertCreated();

    $ride = Ride::where('passenger_id', $this->passenger->id)->first();
    expect($ride->payment_method)->toBe(PaymentMethod::Wallet);

    $hold = Hold::where('ride_id', $ride->id)->first();
    expect($hold)->not->toBeNull();
    expect($hold->status)->toBe(HoldStatus::Active);
    expect($hold->amount)->toBeGreaterThan(0);
});

test('wallet ride creation fails with insufficient balance', function () {
    Queue::fake();

    $this->passengerAccount->update(['balance' => 100]);

    $response = $this->withToken($this->token)->postJson('/api/v1/rides', [
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'pickup_lat' => 9.0579,
        'pickup_lng' => 7.4951,
        'pickup_address' => 'Wuse 2, Abuja',
        'destination_lat' => 9.0765,
        'destination_lng' => 7.3986,
        'destination_address' => 'Garki, Abuja',
        'payment_method' => 'wallet',
    ]);

    $response->assertStatus(422);
});

test('cash and card rides do not place wallet holds', function () {
    Queue::fake();

    $response = $this->withToken($this->token)->postJson('/api/v1/rides', [
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'pickup_lat' => 9.0579,
        'pickup_lng' => 7.4951,
        'pickup_address' => 'Wuse 2, Abuja',
        'destination_lat' => 9.0765,
        'destination_lng' => 7.3986,
        'destination_address' => 'Garki, Abuja',
        'payment_method' => 'cash',
    ]);

    $response->assertCreated();
    expect(Hold::count())->toBe(0);
});

// --- BE-WAL-15: Wallet settlement on ride completion ---

test('wallet payment settles ride via PaymentService', function () {
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'payment_method' => PaymentMethod::Wallet,
        'final_fare_amount' => 2500,
    ]);

    Hold::factory()->create([
        'account_id' => $this->passengerAccount->id,
        'ride_id' => $ride->id,
        'amount' => 300000,
        'status' => HoldStatus::Active,
    ]);

    $paymentService = app(PaymentService::class);
    $payment = $paymentService->processRidePayment($ride);

    expect($payment->method)->toBe(PaymentMethod::Wallet);
    expect($payment->status)->toBe(PaymentStatus::Captured);

    $hold = Hold::where('ride_id', $ride->id)->first();
    expect($hold->status)->toBe(HoldStatus::Captured);
});

// --- BE-WAL-12: Insufficient balance at settlement ---

test('settle handles shortfall when final fare exceeds balance', function () {
    $service = app(WalletPaymentService::class);

    $this->passengerAccount->update(['balance' => 200000]);

    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'payment_method' => PaymentMethod::Wallet,
        'final_fare_amount' => 3000,
    ]);

    Hold::factory()->create([
        'account_id' => $this->passengerAccount->id,
        'ride_id' => $ride->id,
        'amount' => 200000,
        'status' => HoldStatus::Active,
    ]);

    $result = $service->settle($ride, 300000);

    expect($result['settled'])->toBeFalse();
    expect($result['amount_charged'])->toBe(200000);
    expect($result['shortfall'])->toBe(100000);

    $ride->refresh();
    expect($ride->payment_status)->toBe(PaymentStatus::Failed);

    $snapshot = $ride->pricing_snapshot;
    expect($snapshot['wallet_shortfall_kobo'])->toBe(100000);
});

test('settle returns full settlement when balance covers fare', function () {
    $service = app(WalletPaymentService::class);

    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'payment_method' => PaymentMethod::Wallet,
    ]);

    $service->placeHold($this->passenger, $ride, 300000);

    $result = $service->settle($ride, 250000);

    expect($result['settled'])->toBeTrue();
    expect($result['amount_charged'])->toBe(250000);
    expect($result['shortfall'])->toBe(0);
});

// --- Hold release on cancellation ---

test('cancelling a wallet ride releases the hold', function () {
    Queue::fake();
    $service = app(WalletPaymentService::class);

    $ride = Ride::factory()->searching()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'payment_method' => PaymentMethod::Wallet,
    ]);

    $service->placeHold($this->passenger, $ride, 300000);

    $rideService = app(RideService::class);
    $rideService->cancelRide($ride, $this->passenger, 'changed_mind');

    $hold = Hold::where('ride_id', $ride->id)->first();
    expect($hold->status)->toBe(HoldStatus::Released);
});

// --- Hold release on no-driver-found ---

test('matching timeout releases wallet hold', function () {
    $service = app(WalletPaymentService::class);

    $ride = Ride::factory()->searching()->create([
        'passenger_id' => $this->passenger->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'payment_method' => PaymentMethod::Wallet,
    ]);

    $service->placeHold($this->passenger, $ride, 300000);

    $job = new MatchingTimeoutJob($ride->id);
    $job->handle(
        app(RideStateMachine::class),
        app(DriverMatchingService::class),
        app(WalletPaymentService::class),
    );

    $hold = Hold::where('ride_id', $ride->id)->first();
    expect($hold->status)->toBe(HoldStatus::Released);

    $ride->refresh();
    expect($ride->status)->toBe(RideStatus::NoDriverFound);
});

// --- BE-WAL-08: ExpireAbandonedTopupsJob ---

test('ExpireAbandonedTopupsJob marks stale pending topups as abandoned', function () {
    $tx1 = WalletTransaction::create([
        'account_id' => $this->passengerAccount->id,
        'reference' => 'TOPUP-OLD001',
        'amount' => 100000,
        'status' => WalletTransactionStatus::Pending,
    ]);
    DB::table('wallet_transactions')->where('id', $tx1->id)->update(['created_at' => now()->subMinutes(45)]);

    $tx2 = WalletTransaction::create([
        'account_id' => $this->passengerAccount->id,
        'reference' => 'TOPUP-NEW001',
        'amount' => 200000,
        'status' => WalletTransactionStatus::Pending,
    ]);

    $tx3 = WalletTransaction::create([
        'account_id' => $this->passengerAccount->id,
        'reference' => 'TOPUP-DONE01',
        'amount' => 150000,
        'status' => WalletTransactionStatus::Completed,
        'completed_at' => now()->subMinutes(60),
    ]);
    WalletTransaction::withoutTimestamps(fn () => $tx3->update(['created_at' => now()->subMinutes(60)]));

    (new ExpireAbandonedTopupsJob)->handle();

    $tx1->refresh();
    $tx2->refresh();
    $tx3->refresh();

    expect($tx1->status)->toBe(WalletTransactionStatus::Abandoned);
    expect($tx1->abandoned_at)->not->toBeNull();
    expect($tx2->status)->toBe(WalletTransactionStatus::Pending);
    expect($tx3->status)->toBe(WalletTransactionStatus::Completed);
});

test('ExpireAbandonedTopupsJob does nothing when no stale topups exist', function () {
    WalletTransaction::create([
        'account_id' => $this->passengerAccount->id,
        'reference' => 'TOPUP-RECENT1',
        'amount' => 100000,
        'status' => WalletTransactionStatus::Pending,
    ]);

    (new ExpireAbandonedTopupsJob)->handle();

    expect(WalletTransaction::where('status', WalletTransactionStatus::Pending)->count())->toBe(1);
    expect(WalletTransaction::where('status', WalletTransactionStatus::Abandoned)->count())->toBe(0);
});

// --- processWalletPayment creates proper Payment records ---

test('processWalletPayment creates payment record with captured status', function () {
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'payment_method' => PaymentMethod::Wallet,
        'final_fare_amount' => 2000,
    ]);

    Hold::factory()->create([
        'account_id' => $this->passengerAccount->id,
        'ride_id' => $ride->id,
        'amount' => 200000,
        'status' => HoldStatus::Active,
    ]);

    $paymentService = app(PaymentService::class);
    $payment = $paymentService->processWalletPayment($ride);

    expect($payment->method)->toBe(PaymentMethod::Wallet);
    expect($payment->status)->toBe(PaymentStatus::Captured);

    $this->passengerAccount->refresh();
    expect($this->passengerAccount->balance)->toBe(800000);
});
