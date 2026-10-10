<?php

use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\UserType;
use App\Jobs\SettleDriverEarningsJob;
use App\Models\Account;
use App\Models\AppSetting;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Services\DriverEarningsService;
use App\Services\LedgerService;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);
    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->driverUser->id]);
    $this->driverToken = $this->driverUser->createToken('test', ['driver'])->plainTextToken;

    $this->admin = User::factory()->admin()->create(['admin_role' => AdminRole::Finance]);
    $this->adminToken = $this->admin->createToken('test', ['admin'])->plainTextToken;
});

// --- BE-EARN-04: SettleDriverEarningsJob ---

test('SettleDriverEarningsJob moves earnings from pending to available', function () {
    config(['wallet.settlement_delay' => '24h']);

    $ledger = app(LedgerService::class);
    $pendingAccount = $ledger->findOrCreateAccount(
        'App\\Models\\Driver',
        $this->driver->id,
        AccountType::DriverEarningsPending,
    );
    $availableAccount = $ledger->findOrCreateAccount(
        'App\\Models\\Driver',
        $this->driver->id,
        AccountType::DriverEarningsAvailable,
    );

    $pspAccount = $ledger->systemAccount(AccountType::PspClearing);
    $ledger->postJournal([
        ['account_id' => $pspAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $pendingAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 100000],
    ], [
        'description' => 'Test credit to pending',
        'idempotency_key' => 'test-pending-credit-1',
    ]);

    $pendingAccount->refresh();
    expect($pendingAccount->balance)->toBe(100000);

    DB::table('ledger_entries')
        ->where('account_id', $pendingAccount->id)
        ->update(['created_at' => now()->subHours(25)]);

    (new SettleDriverEarningsJob)->handle($ledger);

    $pendingAccount->refresh();
    $availableAccount->refresh();
    expect($pendingAccount->balance)->toBe(0);
    expect($availableAccount->balance)->toBe(100000);
});

test('SettleDriverEarningsJob skips when settlement delay is instant', function () {
    config(['wallet.settlement_delay' => 'instant']);

    $ledger = app(LedgerService::class);
    $pendingAccount = $ledger->findOrCreateAccount(
        'App\\Models\\Driver',
        $this->driver->id,
        AccountType::DriverEarningsPending,
    );

    $pspAccount = $ledger->systemAccount(AccountType::PspClearing);
    $ledger->postJournal([
        ['account_id' => $pspAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 50000],
        ['account_id' => $pendingAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 50000],
    ], [
        'description' => 'Test',
        'idempotency_key' => 'test-skip-1',
    ]);

    (new SettleDriverEarningsJob)->handle($ledger);

    $pendingAccount->refresh();
    expect($pendingAccount->balance)->toBe(50000);
});

// --- BE-EARN-05: Cash-ride commission ---

test('cash-ride commission debits driver earnings available account', function () {
    $service = app(DriverEarningsService::class);

    $ride = Ride::factory()->completed()->create([
        'driver_id' => $this->driverUser->id,
        'payment_method' => PaymentMethod::Cash,
        'final_fare_amount' => 2000,
    ]);

    $service->creditRideEarnings($ride, 200000);

    $driverAccount = Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $this->driver->id)
        ->where('type', AccountType::DriverEarningsAvailable)
        ->first();

    expect($driverAccount->balance)->toBe(-40000);

    $platformAccount = app(LedgerService::class)->systemAccount(AccountType::PlatformCommission);
    expect($platformAccount->balance)->toBe(40000);
});

test('card-ride earnings go to pending when settlement delay is not instant', function () {
    config(['wallet.settlement_delay' => '24h']);
    $service = app(DriverEarningsService::class);

    $ride = Ride::factory()->completed()->create([
        'driver_id' => $this->driverUser->id,
        'payment_method' => PaymentMethod::Card,
        'final_fare_amount' => 2000,
    ]);

    $service->creditRideEarnings($ride, 200000);

    $pendingAccount = Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $this->driver->id)
        ->where('type', AccountType::DriverEarningsPending)
        ->first();

    expect($pendingAccount)->not->toBeNull();
    expect($pendingAccount->balance)->toBe(160000);
});

test('hasExcessiveNegativeBalance returns true when threshold exceeded', function () {
    $service = app(DriverEarningsService::class);
    $ledger = app(LedgerService::class);

    $driverAccount = $ledger->findOrCreateAccount(
        'App\\Models\\Driver',
        $this->driver->id,
        AccountType::DriverEarningsAvailable,
    );

    $platformAccount = $ledger->systemAccount(AccountType::PlatformCommission);
    $ledger->postJournal([
        ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 600000],
        ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 600000],
    ], [
        'description' => 'Large commission debit',
        'idempotency_key' => 'test-negative-1',
    ]);

    expect($service->hasExcessiveNegativeBalance($this->driver->id))->toBeTrue();
});

// --- BE-EARN-06: DriverEarningsController@show with balances ---

test('driver earnings endpoint returns balances and summaries', function () {
    $ledger = app(LedgerService::class);
    $driverAccount = $ledger->findOrCreateAccount(
        'App\\Models\\Driver',
        $this->driver->id,
        AccountType::DriverEarningsAvailable,
    );

    $pspAccount = $ledger->systemAccount(AccountType::PspClearing);
    $ledger->postJournal([
        ['account_id' => $pspAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 300000],
        ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 300000],
    ], [
        'description' => 'Test',
        'idempotency_key' => 'test-balance-1',
    ]);

    Ride::factory()->completed()->create([
        'driver_id' => $this->driverUser->id,
        'completed_at' => now(),
        'final_fare_amount' => 2500,
    ]);

    $response = $this->withToken($this->driverToken)->getJson('/api/v1/driver/earnings');

    $response->assertOk()
        ->assertJsonPath('earnings.balances.available_kobo', 300000)
        ->assertJsonPath('earnings.balances.pending_kobo', 0)
        ->assertJsonPath('earnings.summaries.today', 2500);
});

// --- BE-EARN-07: Ride breakdown ---

test('driver can view ride earnings breakdown', function () {
    $ride = Ride::factory()->completed()->create([
        'driver_id' => $this->driverUser->id,
        'final_fare_amount' => 2000,
        'payment_method' => PaymentMethod::Cash,
    ]);

    $response = $this->withToken($this->driverToken)
        ->getJson("/api/v1/driver/earnings/rides/{$ride->id}");

    $response->assertOk()
        ->assertJsonPath('breakdown.fare_kobo', 200000)
        ->assertJsonPath('breakdown.commission_rate', 0.2)
        ->assertJsonPath('breakdown.commission_kobo', 40000)
        ->assertJsonPath('breakdown.net_earnings_kobo', 160000)
        ->assertJsonPath('breakdown.payment_method', 'cash');
});

test('driver cannot view another drivers ride breakdown', function () {
    $otherDriver = User::factory()->create(['type' => UserType::Driver]);
    $ride = Ride::factory()->completed()->create([
        'driver_id' => $otherDriver->id,
        'final_fare_amount' => 2000,
    ]);

    $response = $this->withToken($this->driverToken)
        ->getJson("/api/v1/driver/earnings/rides/{$ride->id}");

    $response->assertForbidden();
});

// --- BE-WADM-20: AdminWalletSettingsController ---

test('admin can view wallet settings', function () {
    $response = $this->withToken($this->adminToken)->getJson('/api/v1/admin/settings/wallet');

    $response->assertOk()
        ->assertJsonPath('settings.min_topup', 50000)
        ->assertJsonPath('settings.max_balance', 50000000)
        ->assertJsonPath('settings.settlement_delay', 'instant');
});

test('admin can update wallet settings', function () {
    $response = $this->withToken($this->adminToken)->putJson('/api/v1/admin/settings/wallet', [
        'min_topup' => 100000,
        'settlement_delay' => '24h',
    ]);

    $response->assertOk()
        ->assertJsonPath('settings.min_topup', 100000)
        ->assertJsonPath('settings.settlement_delay', '24h');

    expect(AppSetting::getValue('wallet.min_topup'))->toBe('100000');
    expect(AppSetting::getValue('wallet.settlement_delay'))->toBe('24h');
});

test('admin wallet settings validates input', function () {
    $response = $this->withToken($this->adminToken)->putJson('/api/v1/admin/settings/wallet', [
        'settlement_delay' => 'invalid',
        'min_topup' => -100,
    ]);

    $response->assertUnprocessable();
});
