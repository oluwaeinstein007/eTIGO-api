<?php

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Models\Account;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Services\DriverEarningsService;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);
    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->driverUser->id]);
    $this->driverToken = $this->driverUser->createToken('test', ['driver'])->plainTextToken;

    $this->otherDriverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->otherDriver = Driver::factory()->approved()->create(['user_id' => $this->otherDriverUser->id]);
    $this->otherDriverToken = $this->otherDriverUser->createToken('test', ['driver'])->plainTextToken;
});

test('driver can view single transaction with linked ride source information', function () {
    $ride = Ride::factory()->create([
        'driver_id' => $this->driverUser->id,
        'payment_method' => PaymentMethod::Card,
        'final_fare_amount' => 5000.00,
    ]);

    app(DriverEarningsService::class)->creditRideEarnings($ride, (int) ($ride->final_fare_amount * 100));

    $driverAccount = Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $this->driver->id)
        ->where('type', AccountType::DriverEarningsAvailable)
        ->first();

    $entries = $driverAccount->entries()->with('journal')->get();
    expect($entries)->toHaveCount(2);

    $creditEntry = $entries->firstWhere('type', LedgerEntryType::Credit);
    $debitEntry = $entries->firstWhere('type', LedgerEntryType::Debit);

    // Test credit entry (trip fare)
    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/ledger/transactions/{$creditEntry->id}");

    $response->assertOk()
        ->assertJsonPath('transaction.id', $creditEntry->id)
        ->assertJsonPath('transaction.type', 'credit')
        ->assertJsonPath('transaction.amount', 500000)
        ->assertJsonPath('transaction.source_type', 'ride')
        ->assertJsonPath('transaction.source_id', $ride->id)
        ->assertJsonPath('transaction.journal.metadata.ride_id', $ride->id)
        ->assertJsonPath('transaction.journal.metadata.category', 'ride_fare');

    // Test debit entry (platform commission)
    $commissionResponse = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/ledger/transactions/{$debitEntry->id}");

    $commissionResponse->assertOk()
        ->assertJsonPath('transaction.id', $debitEntry->id)
        ->assertJsonPath('transaction.type', 'debit')
        ->assertJsonPath('transaction.amount', 100000)
        ->assertJsonPath('transaction.source_type', 'ride')
        ->assertJsonPath('transaction.source_id', $ride->id)
        ->assertJsonPath('transaction.journal.metadata.ride_id', $ride->id)
        ->assertJsonPath('transaction.journal.metadata.category', 'commission');
});

test('driver cannot view transactions belonging to another driver', function () {
    $ride = Ride::factory()->create([
        'driver_id' => $this->otherDriverUser->id,
        'payment_method' => PaymentMethod::Card,
        'final_fare_amount' => 3000.00,
    ]);

    app(DriverEarningsService::class)->creditRideEarnings($ride, (int) ($ride->final_fare_amount * 100));

    $otherDriverAccount = Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $this->otherDriver->id)
        ->where('type', AccountType::DriverEarningsAvailable)
        ->first();

    $entry = $otherDriverAccount->entries()->first();

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/ledger/transactions/{$entry->id}");

    $response->assertNotFound();
});

test('ledger transactions index includes source_type and source_id', function () {
    $ride = Ride::factory()->create([
        'driver_id' => $this->driverUser->id,
        'payment_method' => PaymentMethod::Card,
        'final_fare_amount' => 2500.00,
    ]);

    app(DriverEarningsService::class)->creditRideEarnings($ride, (int) ($ride->final_fare_amount * 100));

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson('/api/v1/driver/ledger/transactions');

    $response->assertOk()
        ->assertJsonCount(2, 'transactions')
        ->assertJsonPath('transactions.0.source_type', 'ride')
        ->assertJsonPath('transactions.0.source_id', $ride->id);
});
