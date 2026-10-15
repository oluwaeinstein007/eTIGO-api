<?php

use App\Contracts\PaymentGateway;
use App\Contracts\WalletGateway;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\HoldStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\VehicleOwnershipType;
use App\Enums\WalletTransactionStatus;
use App\Exceptions\PaymentProviderException;
use App\Jobs\ExpireStaleHoldsJob;
use App\Jobs\ProcessPayoutTransferJob;
use App\Jobs\ProcessPayoutWebhookJob;
use App\Jobs\ReconcilePayoutsJob;
use App\Jobs\SettleDriverEarningsJob;
use App\Jobs\StuckTransactionSweeperJob;
use App\Models\Account;
use App\Models\Adjustment;
use App\Models\AppSetting;
use App\Models\BankAccount;
use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\Hold;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Ride;
use App\Models\User;
use App\Models\UserPaymentMethod;
use App\Models\Vehicle;
use App\Models\WalletTransaction;
use App\Services\DriverEarningsService;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\PayoutLedgerService;
use App\Services\RideSettlementService;
use App\Services\WalletRefundService;
use Database\Seeders\SystemAccountSeeder;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed(SystemAccountSeeder::class);
    $this->ledger = app(LedgerService::class);

    $this->finance = User::factory()->finance()->create();
    $this->passenger = User::factory()->passenger()->create();
    $this->driverUser = User::factory()->driver()->create();
    $this->driver = Driver::factory()->approved()->for($this->driverUser)->create();
});

function driverAvailableKobo(object $test): int
{
    return (int) Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $test->driver->id)
        ->where('type', AccountType::DriverEarningsAvailable)
        ->value('balance');
}

function fundDriverEarnings(object $test, int $kobo): void
{
    $driverAccount = $test->ledger->findOrCreateAccount('App\\Models\\Driver', $test->driver->id, AccountType::DriverEarningsAvailable);

    $test->ledger->postJournal([
        ['account_id' => $test->ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => $kobo],
        ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => $kobo],
    ], ['description' => 'Test earnings']);
}

function passengerWallet(object $test, ?User $passenger = null): Account
{
    return $test->ledger->findOrCreateAccount('App\\Models\\User', ($passenger ?? $test->passenger)->id, AccountType::PassengerWallet);
}

function approvedPayout(object $test, int $amountKobo): Payout
{
    return Payout::factory()->approved()->create([
        'driver_id' => $test->driver->id,
        'bank_account_id' => BankAccount::factory()->create(['driver_id' => $test->driver->id])->id,
        'amount' => $amountKobo,
    ]);
}

function cardRide(object $test, float $fare): Ride
{
    return Ride::factory()->completed()->create([
        'passenger_id' => $test->passenger->id,
        'driver_id' => $test->driverUser->id,
        'payment_method' => PaymentMethod::Card,
        'final_fare_amount' => $fare,
    ]);
}

// === Runtime wallet settings ===

it('enforces the minimum top-up configured by an admin at runtime', function () {
    AppSetting::setValue('wallet.min_topup', 100000, 'wallet');
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldNotReceive('initializePayment');
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($this->passenger)
        ->postJson('/api/v1/passenger/wallet/topup', ['amount' => 60000, 'callback_url' => 'https://app.etigo.test/wallet'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Minimum top-up amount is ₦1000.']);
});

it('rejects wallet settings whose minimum top-up exceeds the maximum balance', function () {
    $this->actingAs($this->finance)
        ->putJson('/api/v1/admin/settings/wallet', ['min_topup' => 60000000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_topup']);

    $this->assertDatabaseMissing('app_settings', ['key' => 'wallet.min_topup']);
});

// === Top-ups ===

it('lets a passenger who signed up without an email start a wallet top-up', function () {
    $passenger = User::factory()->passenger()->create(['email' => null]);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('initializePayment')->once()
        ->withArgs(fn (array $data) => $data['customer']['email'] === "{$passenger->id}@rider.etigo.ng")
        ->andReturn(['link' => 'https://checkout.paystack.test/abc']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($passenger)
        ->postJson('/api/v1/passenger/wallet/topup', ['amount' => 100000, 'callback_url' => 'https://app.etigo.test/wallet'])
        ->assertOk()
        ->assertJsonPath('payment_link', 'https://checkout.paystack.test/abc');
});

it('credits a verified top-up with the amount the provider collected', function () {
    $wallet = passengerWallet($this);
    WalletTransaction::create(['account_id' => $wallet->id, 'reference' => 'TOPUP-OWN1', 'amount' => 100000, 'status' => WalletTransactionStatus::Pending]);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransaction')->once()->with('TOPUP-OWN1')
        ->andReturn(['status' => 'successful', 'amount' => 100000, 'tx_ref' => 'TOPUP-OWN1']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($this->passenger)
        ->getJson('/api/v1/passenger/wallet/topup/TOPUP-OWN1/verify')
        ->assertOk();

    expect($wallet->fresh()->balance)->toBe(100000);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $this->passenger->id,
        'type' => 'topup_success',
        'title' => 'Top-up Successful',
    ]);
});

it('returns 404 when a passenger verifies another passenger\'s top-up', function () {
    $other = User::factory()->passenger()->create();
    WalletTransaction::create(['account_id' => passengerWallet($this, $other)->id, 'reference' => 'TOPUP-OTHER', 'amount' => 100000, 'status' => WalletTransactionStatus::Pending]);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldNotReceive('verifyTransaction');
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($this->passenger)
        ->getJson('/api/v1/passenger/wallet/topup/TOPUP-OTHER/verify')
        ->assertNotFound();

    expect(passengerWallet($this)->balance)->toBe(0);
});

it('credits a top-up whose webhook never arrived when the sweeper verifies it', function () {
    $wallet = passengerWallet($this);
    $pending = WalletTransaction::create(['account_id' => $wallet->id, 'reference' => 'TOPUP-STUCK', 'amount' => 50000, 'status' => WalletTransactionStatus::Pending]);
    $abandoned = WalletTransaction::create(['account_id' => $wallet->id, 'reference' => 'TOPUP-LATE', 'amount' => 70000, 'status' => WalletTransactionStatus::Abandoned]);
    DB::table('wallet_transactions')->update(['created_at' => now()->subHours(2)]);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransaction')->with('TOPUP-STUCK')
        ->andReturn(['status' => 'successful', 'amount' => 50000, 'tx_ref' => 'TOPUP-STUCK']);
    $gateway->shouldReceive('verifyTransaction')->with('TOPUP-LATE')
        ->andReturn(['status' => 'successful', 'amount' => 70000, 'tx_ref' => 'TOPUP-LATE']);

    (new StuckTransactionSweeperJob)->handle($gateway, $this->ledger);

    expect($pending->fresh()->status)->toBe(WalletTransactionStatus::Completed)
        ->and($abandoned->fresh()->status)->toBe(WalletTransactionStatus::Completed)
        ->and($wallet->fresh()->balance)->toBe(120000);
});

// === Holds ===

it('keeps the wallet hold of a ride that is still in progress past the hold expiry', function () {
    $wallet = passengerWallet($this);
    $liveHold = Hold::factory()->create([
        'account_id' => $wallet->id,
        'ride_id' => Ride::factory()->inProgress()->create(['passenger_id' => $this->passenger->id])->id,
        'expires_at' => now()->subHour(),
    ]);
    $staleHold = Hold::factory()->create([
        'account_id' => $wallet->id,
        'ride_id' => Ride::factory()->cancelled()->create(['passenger_id' => $this->passenger->id])->id,
        'expires_at' => now()->subHour(),
    ]);

    (new ExpireStaleHoldsJob)->handle();

    expect($liveHold->fresh()->status)->toBe(HoldStatus::Active)
        ->and($staleHold->fresh()->status)->toBe(HoldStatus::Expired);
});

// === Tips ===

it('credits a card tip to the driver in full and charges the passenger once', function () {
    $ride = cardRide($this, 5000);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'amount' => 5000]);
    UserPaymentMethod::create([
        'user_id' => $this->passenger->id, 'provider' => 'paystack', 'gateway_token' => 'AUTH_test',
        'card_brand' => 'visa', 'card_last_four' => '4242', 'is_default' => true,
    ]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()
        ->andReturn(['status' => 'successful', 'transaction_id' => 'TIP-1', 'amount' => 500.0]);
    $this->app->instance(PaymentGateway::class, $gateway);

    $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])->assertOk();
    $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])->assertUnprocessable();

    expect(driverAvailableKobo($this))->toBe(50000);
});

function tippableCardRide(object $test): Ride
{
    $ride = cardRide($test, 5000);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'amount' => 5000]);
    UserPaymentMethod::create([
        'user_id' => $test->passenger->id, 'provider' => 'paystack', 'gateway_token' => 'AUTH_test',
        'card_brand' => 'visa', 'card_last_four' => '4242', 'is_default' => true,
    ]);

    return $ride;
}

function serverError(): RequestException
{
    return new RequestException(new ClientResponse(new Psr7Response(502)));
}

it('checks a timed-out card tip with Paystack instead of charging again', function () {
    $ride = tippableCardRide($this);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()->andThrow(new ConnectionException('Operation timed out'));
    $gateway->shouldReceive('verifyTransaction')->once()
        ->andReturn(['status' => 'successful', 'tx_ref' => 'ETIGO-TIP-X', 'transaction_id' => '1', 'amount' => 500.0]);
    $this->app->instance(PaymentGateway::class, $gateway);

    $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])->assertOk();

    expect(driverAvailableKobo($this))->toBe(50000);
});

it('never charges a card tip twice when the first outcome could not be confirmed', function () {
    $ride = tippableCardRide($this);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()->andThrow(serverError());
    $gateway->shouldReceive('verifyTransaction')->once()->andThrow(new ConnectionException('Operation timed out'));
    $this->app->instance(PaymentGateway::class, $gateway);

    $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])
        ->assertUnprocessable()
        ->assertJsonPath('message', PaymentService::TIP_UNCONFIRMED_MESSAGE);
    expect(driverAvailableKobo($this))->toBe(0);

    // Later the bank confirms the first charge: the retry settles it and charges nothing.
    $gateway->shouldReceive('verifyTransaction')->once()
        ->andReturn(['status' => 'successful', 'tx_ref' => 'ETIGO-TIP-X', 'transaction_id' => '1', 'amount' => 500.0]);

    $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 800])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your earlier tip went through, so you were not charged again.');
    expect(driverAvailableKobo($this))->toBe(50000)
        ->and((float) $ride->payment->fresh()->tip_amount)->toBe(500.0);
});

it('lets the passenger try again after a card tip is declined', function () {
    $ride = tippableCardRide($this);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->twice()->andReturn(
        ['status' => 'failed', 'transaction_id' => '1', 'amount' => 500.0],
        ['status' => 'successful', 'transaction_id' => '2', 'amount' => 500.0],
    );
    $this->app->instance(PaymentGateway::class, $gateway);

    $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])->assertUnprocessable();
    $this->actingAs($this->passenger)->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])->assertOk();

    expect(driverAvailableKobo($this))->toBe(50000);
});

function walletRide(object $test): Ride
{
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $test->passenger->id,
        'driver_id' => $test->driverUser->id,
        'payment_method' => PaymentMethod::Wallet,
    ]);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'method' => PaymentMethod::Wallet]);

    return $ride;
}

it('pays a tip on a wallet ride from the wallet to the driver in full', function () {
    $wallet = passengerWallet($this);
    $this->ledger->postJournal([
        ['account_id' => $this->ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $wallet->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 100000],
    ], ['description' => 'Top-up']);
    $ride = walletRide($this);

    $this->actingAs($this->passenger)
        ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])
        ->assertOk()
        ->assertJsonPath('payment.tip_amount', '500.00');

    expect($wallet->fresh()->balance)->toBe(50000)
        ->and(driverAvailableKobo($this))->toBe(50000);
});

it('refuses a wallet tip the balance cannot cover and records nothing', function () {
    $ride = walletRide($this);

    $this->actingAs($this->passenger)
        ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Your wallet balance is too low for this tip.');

    expect((float) $ride->payment->fresh()->tip_amount)->toBe(0.0)
        ->and(driverAvailableKobo($this))->toBe(0);
});

function bankTransferRide(object $test): Ride
{
    $ride = Ride::factory()->completed()->create([
        'passenger_id' => $test->passenger->id,
        'driver_id' => $test->driverUser->id,
        'payment_method' => PaymentMethod::BankTransfer,
    ]);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'method' => PaymentMethod::BankTransfer]);

    return $ride;
}

it('asks how to pay a tip on a bank-transfer ride', function () {
    $ride = bankTransferRide($this);

    $this->actingAs($this->passenger)
        ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source' => 'Choose how to pay the tip: wallet or card.']);
});

it('pays a tip on a bank-transfer ride from the wallet', function () {
    $wallet = passengerWallet($this);
    $this->ledger->postJournal([
        ['account_id' => $this->ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $wallet->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 100000],
    ], ['description' => 'Top-up']);
    $ride = bankTransferRide($this);

    $this->actingAs($this->passenger)
        ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500, 'source' => 'wallet'])
        ->assertOk()
        ->assertJsonPath('payment.tip_method', 'wallet');

    expect($wallet->fresh()->balance)->toBe(50000)
        ->and(driverAvailableKobo($this))->toBe(50000);
});

it('charges a tip on a bank-transfer ride to the saved card', function () {
    UserPaymentMethod::create([
        'user_id' => $this->passenger->id, 'provider' => 'paystack', 'gateway_token' => 'AUTH_test',
        'card_brand' => 'visa', 'card_last_four' => '4242', 'is_default' => true,
    ]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('chargeWithToken')->once()
        ->withArgs(fn (array $data) => (float) $data['amount'] === 500.0)
        ->andReturn(['status' => 'successful', 'transaction_id' => 'TIP-1']);
    $this->app->instance(PaymentGateway::class, $gateway);
    $ride = bankTransferRide($this);

    $this->actingAs($this->passenger)
        ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500, 'source' => 'card'])
        ->assertOk()
        ->assertJsonPath('payment.tip_method', 'card');

    expect(driverAvailableKobo($this))->toBe(50000);
});

it('only records a cash tip on a cash ride', function () {
    $ride = walletRide($this);

    $this->actingAs($this->passenger)
        ->postJson("/api/v1/rides/{$ride->id}/tip", ['amount' => 500, 'source' => 'cash'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['source' => 'Cash tips can only be recorded on cash rides.']);

    expect((float) $ride->payment->fresh()->tip_amount)->toBe(0.0);
});

// === Fleet remittance in the ledger ===

it('moves the fleet remittance share out of the driver\'s withdrawable earnings exactly once', function () {
    $this->driver->update(['vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle]);
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $this->driver->id]);
    $agreement = FleetAgreement::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $vehicle->id,
        'daily_remittance_target' => 3000,
    ]);
    $ride = cardRide($this, 5000);
    $payment = Payment::factory()->captured()->create(['ride_id' => $ride->id, 'amount' => 5000]);

    app(RideSettlementService::class)->applyPayment($ride, $payment);
    app(RideSettlementService::class)->applyPayment($ride->fresh(), $payment);

    expect(driverAvailableKobo($this))->toBe(200000)
        ->and((float) $agreement->fresh()->total_remitted)->toBe(3000.0)
        ->and(DailyRemittance::where('agreement_id', $agreement->id)->value('ride_count'))->toBe(1);
});

// === Earnings settlement ===

it('settles pending earnings for a driver with many ride credits under a delayed policy', function () {
    AppSetting::setValue('wallet.settlement_delay', '24h', 'wallet');
    $pending = $this->ledger->findOrCreateAccount('App\\Models\\Driver', $this->driver->id, AccountType::DriverEarningsPending);
    foreach (range(1, 12) as $credit) {
        $this->ledger->postJournal([
            ['account_id' => $this->ledger->systemAccount(AccountType::PspClearing)->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 10000],
            ['account_id' => $pending->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 10000],
        ], ['description' => "Ride credit {$credit}"]);
    }
    $this->travel(25)->hours();

    (new SettleDriverEarningsJob)->handle($this->ledger);

    expect($pending->fresh()->balance)->toBe(0)
        ->and(driverAvailableKobo($this))->toBe(120000);
});

// === Payouts ===

it('debits the driver again when a failed payout is retried and sent', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    $attempt = 0;
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->andReturn('RCP_driver');
    $gateway->shouldReceive('initiateTransfer')->twice()->andReturnUsing(function () use (&$attempt, $payout) {
        if (++$attempt === 1) {
            throw new RuntimeException('Bank unavailable');
        }

        return ['id' => 'TRF-1', 'reference' => "PAYOUT-{$payout->id}", 'status' => 'pending'];
    });
    // The refused first attempt never created a transfer under its reference.
    $gateway->shouldReceive('verifyTransfer')->once()->with("PAYOUT-{$payout->id}")->andReturnNull();
    $this->app->instance(WalletGateway::class, $gateway);
    app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']);
    expect($payout->fresh()->status)->toBe(PayoutStatus::Failed)
        ->and(driverAvailableKobo($this))->toBe(500000);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/retry")->assertOk();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and(driverAvailableKobo($this))->toBe(300000);
});

it('sends a retried payout under a new reference once Paystack has failed the earlier transfer', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    $payout->update(['status' => PayoutStatus::Failed, 'gateway_reference' => "PAYOUT-{$payout->id}", 'gateway_transfer_id' => 'TRF-OLD']);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->andReturn('RCP_driver');
    $gateway->shouldReceive('verifyTransfer')->once()->with("PAYOUT-{$payout->id}")
        ->andReturn(['id' => 'TRF-OLD', 'reference' => "PAYOUT-{$payout->id}", 'status' => 'failed']);
    $gateway->shouldReceive('initiateTransfer')->once()
        ->withArgs(fn (array $data) => $data['reference'] === "PAYOUT-{$payout->id}-2")
        ->andReturn(['id' => 'TRF-NEW', 'reference' => "PAYOUT-{$payout->id}-2", 'status' => 'pending']);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/retry")->assertOk();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and($payout->fresh()->gateway_transfer_id)->toBe('TRF-NEW')
        ->and(driverAvailableKobo($this))->toBe(300000);
});

it('marks a payout paid without sending it again when the earlier transfer went through', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    // The previous try timed out after Paystack had accepted the transfer.
    $payout->update(['gateway_reference' => "PAYOUT-{$payout->id}"]);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransfer')->once()
        ->andReturn(['id' => 'TRF-9', 'reference' => "PAYOUT-{$payout->id}", 'status' => 'success']);
    $gateway->shouldNotReceive('initiateTransfer');
    $this->app->instance(WalletGateway::class, $gateway);

    app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and(driverAvailableKobo($this))->toBe(300000);
});

// --- Payouts whose transfer outcome is unknown (Paystack unreachable, webhook missing) ---

function stuckPayout(object $test, PayoutStatus $status = PayoutStatus::Approved): Payout
{
    // The transfer job reserved the money and set the reference, then lost touch with Paystack.
    $payout = approvedPayout($test, 200000);
    app(PayoutLedgerService::class)->debit($payout);
    $payout->update([
        'status' => $status,
        'gateway_reference' => "PAYOUT-{$payout->id}",
        'approved_at' => now()->subMinutes(30),
    ]);

    return $payout->fresh();
}

function paystackKnowsTransfer(object $test, Payout $payout, ?string $status): MockInterface
{
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->andReturn('RCP_driver');
    $gateway->shouldReceive('verifyTransfer')->with("PAYOUT-{$payout->id}")
        ->andReturn($status === null ? null : ['id' => '82738471', 'reference' => "PAYOUT-{$payout->id}", 'status' => $status]);
    app()->instance(WalletGateway::class, $gateway);

    return $gateway;
}

it('keeps the money reserved when every attempt to reach Paystack fails', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->andReturn('RCP_driver');
    $gateway->shouldReceive('initiateTransfer')->andThrow(new ConnectionException('Connection timed out'));
    $this->app->instance(WalletGateway::class, $gateway);
    $job = new ProcessPayoutTransferJob($payout->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(ConnectionException::class);
    $job->failed(new ConnectionException('Connection timed out'));

    expect($payout->fresh()->status)->toBe(PayoutStatus::Approved)
        ->and(driverAvailableKobo($this))->toBe(300000);
});

it('treats a Paystack server error on a transfer as an unknown outcome, not a failure', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->andReturn('RCP_driver');
    $gateway->shouldReceive('initiateTransfer')->once()
        ->andThrow(new PaymentProviderException('Paystack API error: 504 - Gateway Timeout', 504));
    $this->app->instance(WalletGateway::class, $gateway);

    expect(fn () => app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']))
        ->toThrow(PaymentProviderException::class);
    expect($payout->fresh()->status)->toBe(PayoutStatus::Approved)
        ->and(driverAvailableKobo($this))->toBe(300000);

    // Paystack had created the transfer after all: the retry finds it and does not send again.
    $gateway->shouldReceive('verifyTransfer')->once()->with("PAYOUT-{$payout->id}")
        ->andReturn(['id' => '82738471', 'reference' => "PAYOUT-{$payout->id}", 'status' => 'pending']);
    app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and(driverAvailableKobo($this))->toBe(300000);
});

it('fails a payout and returns the money when Paystack refuses the transfer', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->andReturn('RCP_driver');
    $gateway->shouldReceive('initiateTransfer')->once()
        ->andThrow(new PaymentProviderException('Paystack API error: 400 - Account number is invalid', 400));
    $this->app->instance(WalletGateway::class, $gateway);

    app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Failed)
        ->and(driverAvailableKobo($this))->toBe(500000);
});

it('flags a transfer Paystack holds for an OTP straight away', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('createTransferRecipient')->andReturn('RCP_driver');
    $gateway->shouldReceive('initiateTransfer')->once()
        ->andReturn(['id' => '82738471', 'reference' => "PAYOUT-{$payout->id}", 'status' => 'otp']);
    $this->app->instance(WalletGateway::class, $gateway);

    app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']);

    $this->actingAs($this->finance)
        ->getJson('/api/v1/admin/payouts?status=processing')
        ->assertOk()
        ->assertJsonPath('payouts.0.needs_attention', true)
        ->assertJsonPath('payouts.0.attention_reason', 'awaiting_otp')
        ->assertJsonPath('payouts.0.provider_status', 'otp');
});

it('raises the OTP alert when reconciliation finds a transfer held for an OTP', function () {
    $critical = [];
    Log::listen(function (MessageLogged $log) use (&$critical) {
        if ($log->level === 'critical') {
            $critical[] = $log->message;
        }
    });
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this);
    paystackKnowsTransfer($this, $payout, 'otp');

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/verify")
        ->assertOk()
        ->assertJsonPath('payout.attention_reason', 'awaiting_otp');

    expect(collect($critical)->filter(fn (string $message) => str_contains($message, 'OTP')))->toHaveCount(1);
});

it('flags a payout whose transfer outcome is overdue for finance', function () {
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this);

    $this->actingAs($this->finance)
        ->getJson('/api/v1/admin/payouts?status=approved')
        ->assertOk()
        ->assertJsonPath('payouts.0.id', $payout->id)
        ->assertJsonPath('payouts.0.needs_attention', true)
        ->assertJsonPath('payouts.0.attention_reason', 'not_sent');
});

it('marks a stuck payout paid when Paystack confirms the transfer went through', function () {
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this);
    paystackKnowsTransfer($this, $payout, 'success')->shouldNotReceive('initiateTransfer');

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/verify")
        ->assertOk()
        ->assertJsonPath('outcome', 'paid')
        ->assertJsonPath('payout.status', 'paid');

    expect(driverAvailableKobo($this))->toBe(300000);
});

it('returns the money when Paystack reports a stuck transfer as failed', function () {
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this, PayoutStatus::Processing);
    paystackKnowsTransfer($this, $payout, 'failed');

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/verify")
        ->assertOk()
        ->assertJsonPath('outcome', 'failed')
        ->assertJsonPath('payout.status', 'reversed');

    expect(driverAvailableKobo($this))->toBe(500000);
});

it('lets finance retry a payout whose transfer the bank sent back', function () {
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this, PayoutStatus::Processing);
    $gateway = paystackKnowsTransfer($this, $payout, 'reversed');
    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/verify")->assertOk();
    expect($payout->fresh()->status)->toBe(PayoutStatus::Reversed);
    $gateway->shouldReceive('initiateTransfer')->once()
        ->withArgs(fn (array $data) => $data['reference'] === "PAYOUT-{$payout->id}-2")
        ->andReturn(['id' => '82738472', 'reference' => "PAYOUT-{$payout->id}-2", 'status' => 'pending']);

    $this->actingAs($this->finance)->postJson("/api/v1/admin/payouts/{$payout->id}/retry")->assertOk();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and(driverAvailableKobo($this))->toBe(300000);
});

it('sends a stuck payout again under the same reference when Paystack never received it', function () {
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this);
    paystackKnowsTransfer($this, $payout, null)
        ->shouldReceive('initiateTransfer')->once()
        ->withArgs(fn (array $data) => $data['reference'] === "PAYOUT-{$payout->id}")
        ->andReturn(['id' => '82738471', 'reference' => "PAYOUT-{$payout->id}", 'status' => 'pending']);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/verify")
        ->assertOk()
        ->assertJsonPath('outcome', 'resent');

    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and(driverAvailableKobo($this))->toBe(300000);
});

it('tells finance when Paystack cannot be reached and changes nothing', function (Throwable $unavailable) {
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransfer')->andThrow($unavailable);
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/verify")
        ->assertStatus(502);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Approved)
        ->and(driverAvailableKobo($this))->toBe(300000);
})->with([
    'timeout' => fn () => new ConnectionException('Connection timed out'),
    'server error' => fn () => new PaymentProviderException('Paystack API error: 503 - Service Unavailable', 503),
]);

it('does not pass off a Paystack configuration error as an outage', function () {
    fundDriverEarnings($this, 500000);
    $payout = stuckPayout($this);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransfer')
        ->andThrow(new PaymentProviderException('Paystack API error: 401 - Invalid key', 401));
    $this->app->instance(WalletGateway::class, $gateway);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/verify")
        ->assertInternalServerError();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Approved);
});

it('only checks approved or processing payouts with Paystack', function () {
    $payout = Payout::factory()->paid()->create(['driver_id' => $this->driver->id]);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/verify")
        ->assertUnprocessable()
        ->assertJsonPath('current_status', 'paid');
});

it('settles payouts stuck for longer than usual on its own', function () {
    fundDriverEarnings($this, 900000);
    $stuck = stuckPayout($this);
    $fresh = approvedPayout($this, 100000);
    $fresh->update(['approved_at' => now()]);
    $gateway = paystackKnowsTransfer($this, $stuck, 'success');

    app()->call([new ReconcilePayoutsJob, 'handle']);

    expect($stuck->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and($fresh->fresh()->status)->toBe(PayoutStatus::Approved);
    $gateway->shouldHaveReceived('verifyTransfer')->once();
});

it('reports an unexpected error while settling a stuck payout and settles the rest', function () {
    Exceptions::fake();
    fundDriverEarnings($this, 900000);
    $broken = stuckPayout($this);
    $stuck = stuckPayout($this);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransfer')->with("PAYOUT-{$broken->id}")
        ->andThrow(new PaymentProviderException('Paystack API error: 401 - Invalid key', 401));
    $gateway->shouldReceive('verifyTransfer')->with("PAYOUT-{$stuck->id}")
        ->andReturn(['id' => '82738471', 'reference' => "PAYOUT-{$stuck->id}", 'status' => 'success']);
    $this->app->instance(WalletGateway::class, $gateway);

    app()->call([new ReconcilePayoutsJob, 'handle']);

    expect($stuck->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and($broken->fresh()->status)->toBe(PayoutStatus::Approved);
    Exceptions::assertReported(PaymentProviderException::class);
});

it('fails a payout without sending money when the balance no longer covers it', function () {
    fundDriverEarnings($this, 100000);
    $payout = approvedPayout($this, 200000);
    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldNotReceive('initiateTransfer');
    $this->app->instance(WalletGateway::class, $gateway);

    app()->call([new ProcessPayoutTransferJob($payout->id), 'handle']);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Failed)
        ->and($payout->fresh()->failure_reason)->toBe('Insufficient available balance to complete this payout.')
        ->and(driverAvailableKobo($this))->toBe(100000);
});

it('re-debits a written-off payout that the provider later reports as paid', function () {
    fundDriverEarnings($this, 500000);
    $payout = approvedPayout($this, 200000);
    $payout->update(['status' => PayoutStatus::Reversed, 'gateway_reference' => "PAYOUT-{$payout->id}"]);

    app()->call([new ProcessPayoutWebhookJob('TRF-UNKNOWN', 'paid', null, "PAYOUT-{$payout->id}"), 'handle']);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and(driverAvailableKobo($this))->toBe(300000);
});

it('notifies the driver when finance rejects a payout request', function () {
    $payout = Payout::factory()->create(['driver_id' => $this->driver->id]);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/payouts/{$payout->id}/reject", ['reason' => 'Bank account name does not match the driver.'])
        ->assertOk();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Rejected);
    $this->assertDatabaseHas('notifications', ['user_id' => $this->driverUser->id, 'type' => 'payout_rejected']);
});

// === Refunds ===

it('refunds part of a card fare to the wallet and claws back the driver\'s share', function () {
    $ride = cardRide($this, 5000);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'amount' => 5000]);
    app(DriverEarningsService::class)->creditRideEarnings($ride, 500000);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/rides/{$ride->id}/refund", ['amount' => 100000, 'reason' => 'Driver took a much longer route than necessary.'])
        ->assertOk()
        ->assertJsonPath('driver_clawback_kobo', 80000)
        ->assertJsonPath('remaining_refundable_kobo', 400000);

    expect(passengerWallet($this)->balance)->toBe(100000)
        ->and(driverAvailableKobo($this))->toBe(320000);
});

it('rejects a refund larger than what the passenger paid', function () {
    $ride = cardRide($this, 5000);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'amount' => 5000]);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/rides/{$ride->id}/refund", ['amount' => 600000, 'reason' => 'Goodwill refund requested by support.'])
        ->assertUnprocessable()
        ->assertJsonPath('max_refundable_kobo', 500000);

    expect(passengerWallet($this)->balance)->toBe(0);
});

it('never refunds more than the passenger paid when two refunds race', function () {
    $ride = cardRide($this, 5000);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'amount' => 5000]);
    $refunds = app(WalletRefundService::class);

    // Both requests passed the controller's pre-check before either was saved.
    $refunds->refundRide($ride, 500000, 'Trip never took place.', $this->finance, false);

    expect(fn () => $refunds->refundRide($ride, 500000, 'Trip never took place.', $this->finance, false))
        ->toThrow(DomainException::class, 'Refund exceeds remaining refundable amount of ₦0.00.');

    expect(passengerWallet($this)->balance)->toBe(500000);
});

it('does not tell the passenger about a refund that could not be completed', function () {
    Notification::fake();
    $ride = cardRide($this, 5000);
    Payment::factory()->captured()->create(['ride_id' => $ride->id, 'amount' => 5000]);
    app(DriverEarningsService::class)->creditRideEarnings($ride, 500000);
    Account::where('owner_type', 'App\\Models\\Driver')
        ->where('owner_id', $this->driver->id)
        ->where('type', AccountType::DriverEarningsAvailable)
        ->update(['status' => AccountStatus::Frozen]);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/rides/{$ride->id}/refund", ['amount' => 100000, 'reason' => 'Driver took a much longer route than necessary.'])
        ->assertUnprocessable();

    expect(passengerWallet($this)->balance)->toBe(0);
    Notification::assertNothingSent();
});

it('refuses to refund a ride that was never completed', function () {
    $ride = Ride::factory()->cancelled()->create(['passenger_id' => $this->passenger->id]);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/rides/{$ride->id}/refund", ['amount' => 1000, 'reason' => 'Passenger asked for a refund.'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only completed rides can be refunded.');
});

// === Adjustments ===

it('returns 422 when approving an adjustment for a frozen account', function () {
    $wallet = passengerWallet($this);
    $wallet->update(['status' => AccountStatus::Frozen]);
    $adjustment = Adjustment::factory()->create(['account_id' => $wallet->id, 'amount' => 10000]);

    $this->actingAs($this->finance)
        ->postJson("/api/v1/admin/adjustments/{$adjustment->id}/approve")
        ->assertUnprocessable();

    expect($adjustment->fresh()->status->value)->toBe('pending');
});

// === Payment webhooks ===

it('captures a pending card payment from a charge webhook verified by reference', function () {
    Queue::fake();
    config(['services.paystack.secret_key' => 'test_secret_key']);
    $ride = cardRide($this, 5000);
    $payment = Payment::factory()->create(['ride_id' => $ride->id, 'amount' => 5000, 'gateway_tx_ref' => 'ETIGO-RIDE-ABC']);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('verifyTransaction')->once()->with('ETIGO-RIDE-ABC')->andReturn([
        'status' => 'successful', 'tx_ref' => 'ETIGO-RIDE-ABC', 'transaction_id' => '777', 'amount' => 5000.0, 'currency' => 'NGN',
    ]);
    $this->app->instance(PaymentGateway::class, $gateway);
    $payload = ['event' => 'charge.success', 'data' => ['id' => 777, 'reference' => 'ETIGO-RIDE-ABC', 'status' => 'success', 'amount' => 500000]];

    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => hash_hmac('sha512', json_encode($payload), 'test_secret_key'),
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured)
        ->and(driverAvailableKobo($this))->toBe(400000);
});

it('processes a transfer reversal that follows a success for the same transfer', function () {
    Queue::fake();
    config(['services.paystack.secret_key' => 'test_secret_key']);
    $send = function (string $event) {
        $payload = ['event' => $event, 'data' => ['id' => 5555, 'reference' => 'PAYOUT-X', 'status' => $event]];

        return $this->postJson('/api/v1/webhooks/paystack', $payload, [
            'x-paystack-signature' => hash_hmac('sha512', json_encode($payload), 'test_secret_key'),
        ]);
    };

    $send('transfer.success')->assertOk();
    $send('transfer.reversed')->assertOk()->assertJsonPath('message', 'Webhook received.');

    Queue::assertPushed(ProcessPayoutWebhookJob::class, 2);
});

it('processes a charge webhook again when the first delivery failed part-way', function () {
    Queue::fake();
    config(['services.paystack.secret_key' => 'test_secret_key']);
    $ride = cardRide($this, 5000);
    $payment = Payment::factory()->create(['ride_id' => $ride->id, 'amount' => 5000, 'gateway_tx_ref' => 'ETIGO-RIDE-RETRY']);
    $verifyAttempts = 0;
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('verifyTransaction')->with('ETIGO-RIDE-RETRY')->andReturnUsing(function () use (&$verifyAttempts) {
        if (++$verifyAttempts === 1) {
            throw new RuntimeException('Paystack verify timed out.');
        }

        return ['status' => 'successful', 'tx_ref' => 'ETIGO-RIDE-RETRY', 'transaction_id' => '778', 'amount' => 5000.0, 'currency' => 'NGN'];
    });
    $this->app->instance(PaymentGateway::class, $gateway);
    $payload = ['event' => 'charge.success', 'data' => ['id' => 778, 'reference' => 'ETIGO-RIDE-RETRY', 'status' => 'success', 'amount' => 500000]];
    $headers = ['x-paystack-signature' => hash_hmac('sha512', json_encode($payload), 'test_secret_key')];

    $this->postJson('/api/v1/webhooks/paystack', $payload, $headers)->assertServerError();
    $this->postJson('/api/v1/webhooks/paystack', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('message', 'Webhook received.');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Captured);
});

it('treats a Flutterwave transfer.completed event with a failed status as a failed payout', function () {
    Queue::fake();
    config(['services.flutterwave.webhook_secret' => 'flw-secret-hash']);

    $this->postJson('/api/v1/webhooks/flutterwave', [
        'event' => 'transfer.completed',
        'data' => ['id' => 6061, 'reference' => 'PAYOUT-FLW', 'status' => 'FAILED', 'complete_message' => 'Account resolution failed'],
    ], ['verif-hash' => 'flw-secret-hash'])->assertOk();

    Queue::assertPushed(ProcessPayoutWebhookJob::class, fn (ProcessPayoutWebhookJob $job) => $job->status === 'failed'
        && $job->reason === 'Account resolution failed');
});
