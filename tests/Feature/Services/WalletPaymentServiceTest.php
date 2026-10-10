<?php

use App\Enums\AccountType;
use App\Enums\HoldStatus;
use App\Enums\UserType;
use App\Models\Account;
use App\Models\City;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\Hold;
use App\Models\PricingConfig;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\WalletPaymentService;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->passengerAccount = Account::factory()->passengerWallet()->withBalance(1000000)->create([
        'owner_type' => (new User)->getMorphClass(),
        'owner_id' => $this->passenger->id,
    ]);

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

    $this->ride = Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'driver_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'payment_method' => 'wallet',
    ]);

    $this->service = app(WalletPaymentService::class);
});

test('placeHold creates an active hold on passenger wallet', function () {
    $hold = $this->service->placeHold($this->passenger, $this->ride, 300000);

    expect($hold)->toBeInstanceOf(Hold::class);
    expect($hold->status)->toBe(HoldStatus::Active);
    expect($hold->amount)->toBe(300000);
    expect($hold->ride_id)->toBe($this->ride->id);
});

test('placeHold rejects when insufficient balance', function () {
    $this->service->placeHold($this->passenger, $this->ride, 2000000);
})->throws(DomainException::class, 'Insufficient wallet balance');

test('placeHold considers existing holds in available balance', function () {
    Hold::factory()->create([
        'account_id' => $this->passengerAccount->id,
        'amount' => 800000,
    ]);

    $this->service->placeHold($this->passenger, $this->ride, 300000);
})->throws(DomainException::class, 'Insufficient wallet balance');

test('placeHold rejects frozen wallets', function () {
    $this->passengerAccount->update(['status' => 'frozen']);

    $this->service->placeHold($this->passenger, $this->ride, 100000);
})->throws(DomainException::class, 'frozen or closed');

test('settle captures hold and posts journal entries', function () {
    $this->service->placeHold($this->passenger, $this->ride, 300000);

    $this->service->settle($this->ride, 250000);

    $hold = Hold::where('ride_id', $this->ride->id)->first();
    expect($hold->status)->toBe(HoldStatus::Captured);
    expect($hold->captured_at)->not->toBeNull();

    $this->passengerAccount->refresh();
    expect($this->passengerAccount->balance)->toBe(750000);

    $driverAccount = Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $this->driver->id)
        ->where('type', AccountType::DriverEarningsAvailable)
        ->first();

    expect($driverAccount->balance)->toBe(200000);

    $platformAccount = Account::where('type', AccountType::PlatformCommission)
        ->where('owner_type', 'system')
        ->first();
    expect($platformAccount->balance)->toBe(50000);
});

test('settle throws when no active hold exists', function () {
    $this->service->settle($this->ride, 250000);
})->throws(DomainException::class, 'No active hold');

test('releaseHold releases active hold', function () {
    $this->service->placeHold($this->passenger, $this->ride, 300000);

    $this->service->releaseHold($this->ride);

    $hold = Hold::where('ride_id', $this->ride->id)->first();
    expect($hold->status)->toBe(HoldStatus::Released);
    expect($hold->released_at)->not->toBeNull();
});

test('releaseHold is safe when no hold exists', function () {
    $this->service->releaseHold($this->ride);

    expect(true)->toBeTrue();
});
