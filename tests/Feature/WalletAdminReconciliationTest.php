<?php

use App\Contracts\WalletGateway;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\LedgerEntryType;
use App\Enums\NotificationType;
use App\Enums\PayoutStatus;
use App\Enums\UserType;
use App\Enums\WalletTransactionStatus;
use App\Jobs\DailyReconciliationJob;
use App\Jobs\NegativeDriverBalanceReportJob;
use App\Jobs\StuckTransactionSweeperJob;
use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\Payout;
use App\Models\ReconciliationReport;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WebhookEvent;
use App\Notifications\PayoutStatusNotification;
use App\Notifications\WalletTopupNotification;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);
    CommissionConfig::factory()->create(['rate' => 0.2000]);

    $this->admin = User::factory()->admin()->create(['admin_role' => AdminRole::Finance]);
    $this->adminToken = $this->admin->createToken('test', ['admin'])->plainTextToken;
});

// --- BE-WADM-21: DailyReconciliationJob ---

test('DailyReconciliationJob creates a clean report when no mismatches', function () {
    (new DailyReconciliationJob(now()->subDay()->toDateString()))->handle();

    $report = ReconciliationReport::first();
    expect($report)->not->toBeNull();
    expect($report->status)->toBe('clean');
    expect($report->mismatches_count)->toBe(0);
});

test('DailyReconciliationJob is idempotent — skips if report exists', function () {
    $date = now()->subDay()->toDateString();
    (new DailyReconciliationJob($date))->handle();
    (new DailyReconciliationJob($date))->handle();

    expect(ReconciliationReport::count())->toBe(1);
});

test('DailyReconciliationJob detects charge mismatches', function () {
    $yesterday = now()->subDay();

    WebhookEvent::create([
        'provider' => 'paystack',
        'event_id' => 'evt-1',
        'event_type' => 'charge.success',
        'payload' => ['event' => 'charge.success', 'data' => ['reference' => 'TOPUP-NOCREDIT', 'amount' => 50000, 'status' => 'success']],
        'signature' => 'test',
        'processed_at' => $yesterday,
        'created_at' => $yesterday,
    ]);

    (new DailyReconciliationJob($yesterday->toDateString()))->handle();

    $report = ReconciliationReport::first();
    expect($report->status)->toBe('mismatched');
    expect($report->mismatches_count)->toBeGreaterThan(0);
});

test('DailyReconciliationJob reports a clean day when every top-up and payout went through', function () {
    config(['services.paystack.secret_key' => 'test_secret_key']);
    $passenger = User::factory()->create(['type' => UserType::Passenger]);
    $wallet = app(LedgerService::class)->findOrCreateAccount($passenger->getMorphClass(), $passenger->id, AccountType::PassengerWallet);
    $payout = Payout::factory()->create(['status' => PayoutStatus::Processing, 'gateway_transfer_id' => '5551', 'amount' => 250000]);
    $webhooks = [
        ['event' => 'charge.success', 'data' => ['id' => 9101, 'reference' => 'TOPUP-REC1', 'amount' => 500000, 'status' => 'success', 'metadata' => ['account_id' => $wallet->id]]],
        ['event' => 'transfer.success', 'data' => ['id' => 5551, 'reference' => "PAYOUT-{$payout->id}", 'amount' => 250000, 'status' => 'success']],
    ];

    $this->travelTo(now()->subDay());
    foreach ($webhooks as $payload) {
        $this->postJson('/api/v1/webhooks/paystack', $payload, [
            'x-paystack-signature' => hash_hmac('sha512', json_encode($payload), 'test_secret_key'),
        ])->assertOk();
    }
    $this->travelBack();

    (new DailyReconciliationJob(now()->subDay()->toDateString()))->handle();

    $report = ReconciliationReport::first();
    expect($report->status)->toBe('clean')
        ->and($report->gateway_charges_total)->toBe(500000)
        ->and($report->ledger_credits_total)->toBe(500000)
        ->and($report->gateway_transfers_total)->toBe(250000)
        ->and($report->ledger_payouts_total)->toBe(250000);
});

test('DailyReconciliationJob asks Paystack about a payout settled without a webhook', function () {
    $payout = Payout::factory()->create([
        'status' => PayoutStatus::Paid,
        'amount' => 200000,
        'gateway_reference' => 'PAYOUT-VERIFIED-2',
        'paid_at' => now()->subDay(),
    ]);

    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransfer')->once()->with('PAYOUT-VERIFIED-2')
        ->andReturn(['id' => 1043744364, 'reference' => 'PAYOUT-VERIFIED-2', 'status' => 'success', 'amount' => 200000]);
    app()->instance(WalletGateway::class, $gateway);

    (new DailyReconciliationJob(now()->subDay()->toDateString()))->handle();

    $report = ReconciliationReport::first();
    expect($report->status)->toBe('clean')
        ->and($report->gateway_transfers_total)->toBe(200000)
        ->and($report->ledger_payouts_total)->toBe(200000);
});

test('DailyReconciliationJob flags a paid payout that Paystack does not confirm', function (?array $transfer) {
    Payout::factory()->create([
        'status' => PayoutStatus::Paid,
        'amount' => 200000,
        'gateway_reference' => 'PAYOUT-UNCONFIRMED',
        'paid_at' => now()->subDay(),
    ]);

    $gateway = Mockery::mock(WalletGateway::class);
    $expectation = $gateway->shouldReceive('verifyTransfer')->once();
    $transfer === null ? $expectation->andThrow(new RuntimeException('Gateway unavailable')) : $expectation->andReturn($transfer);
    app()->instance(WalletGateway::class, $gateway);

    (new DailyReconciliationJob(now()->subDay()->toDateString()))->handle();

    $report = ReconciliationReport::first();
    expect($report->status)->toBe('mismatched')
        ->and($report->gateway_transfers_total)->toBe(0)
        ->and($report->ledger_payouts_total)->toBe(200000);
})->with([
    'failed at Paystack' => [['id' => 1, 'reference' => 'PAYOUT-UNCONFIRMED', 'status' => 'failed', 'amount' => 200000]],
    'Paystack unreachable' => [null],
]);

test('DailyReconciliationJob does not ask Paystack about a payout whose webhook arrived', function () {
    config(['services.paystack.secret_key' => 'test_secret_key']);
    $payout = Payout::factory()->create(['status' => PayoutStatus::Processing, 'gateway_transfer_id' => '5552', 'amount' => 250000]);
    $payload = ['event' => 'transfer.success', 'data' => ['id' => 5552, 'reference' => "PAYOUT-{$payout->id}", 'amount' => 250000, 'status' => 'success']];

    $this->travelTo(now()->subDay());
    $this->postJson('/api/v1/webhooks/paystack', $payload, [
        'x-paystack-signature' => hash_hmac('sha512', json_encode($payload), 'test_secret_key'),
    ])->assertOk();
    $this->travelBack();

    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldNotReceive('verifyTransfer');
    app()->instance(WalletGateway::class, $gateway);

    (new DailyReconciliationJob(now()->subDay()->toDateString()))->handle();

    expect(ReconciliationReport::first()->status)->toBe('clean');
});

// --- BE-WADM-22: StuckTransactionSweeperJob ---

test('StuckTransactionSweeperJob marks old pending transactions as abandoned', function () {
    $passenger = User::factory()->create(['type' => UserType::Passenger]);
    $ledger = app(LedgerService::class);
    $account = $ledger->findOrCreateAccount('App\\Models\\User', $passenger->id, AccountType::PassengerWallet);

    $tx = WalletTransaction::create([
        'account_id' => $account->id,
        'reference' => 'TOPUP-STUCK1',
        'amount' => 100000,
        'status' => WalletTransactionStatus::Pending,
        'gateway_transaction_id' => '12345',
    ]);

    DB::table('wallet_transactions')
        ->where('id', $tx->id)
        ->update(['created_at' => now()->subHours(2)]);

    $gateway = Mockery::mock(WalletGateway::class);
    $gateway->shouldReceive('verifyTransaction')
        ->once()
        ->andThrow(new RuntimeException('Gateway unavailable'));

    (new StuckTransactionSweeperJob)->handle($gateway, $ledger);

    $tx->refresh();
    expect($tx->status)->toBe(WalletTransactionStatus::Abandoned);
});

// --- BE-WADM-23: NegativeDriverBalanceReportJob ---

test('NegativeDriverBalanceReportJob runs without errors', function () {
    $driverUser = User::factory()->create(['type' => UserType::Driver]);
    $driver = Driver::factory()->approved()->create(['user_id' => $driverUser->id]);
    $ledger = app(LedgerService::class);

    $driverAccount = $ledger->findOrCreateAccount('App\\Models\\Driver', $driver->id, AccountType::DriverEarningsAvailable);
    $platformAccount = $ledger->systemAccount(AccountType::PlatformCommission);

    $ledger->postJournal([
        ['account_id' => $driverAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $platformAccount->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 100000],
    ], [
        'description' => 'Test negative',
        'idempotency_key' => 'neg-test-1',
    ]);

    Log::spy();

    (new NegativeDriverBalanceReportJob)->handle();

    $driverAccount->refresh();
    expect($driverAccount->balance)->toBe(-100000);

    Log::shouldHaveReceived('warning')->once();
});

// --- BE-WADM-24: AdminReconciliationController@show ---

test('admin can view reconciliation report for a date', function () {
    ReconciliationReport::create([
        'report_date' => '2026-10-09',
        'gateway_charges_total' => 500000,
        'ledger_credits_total' => 500000,
        'gateway_transfers_total' => 200000,
        'ledger_payouts_total' => 200000,
        'mismatches_count' => 0,
        'status' => 'clean',
    ]);

    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/reports/reconciliation?date=2026-10-09');

    $response->assertOk()
        ->assertJsonPath('report.status', 'clean')
        ->assertJsonPath('report.gateway_charges_total', 500000);
});

test('admin gets 404 for missing reconciliation report', function () {
    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/reports/reconciliation?date=2020-01-01');

    $response->assertNotFound();
});

test('admin can list reconciliation report history', function () {
    ReconciliationReport::create([
        'report_date' => '2026-10-08',
        'gateway_charges_total' => 0,
        'ledger_credits_total' => 0,
        'gateway_transfers_total' => 0,
        'ledger_payouts_total' => 0,
        'mismatches_count' => 0,
        'status' => 'clean',
    ]);

    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/reports/reconciliation/history');

    $response->assertOk()
        ->assertJsonPath('data.0.status', 'clean');
});

// --- BE-WADM-26 & 27: Notification enum and dispatches ---

test('NotificationType enum contains all wallet events', function () {
    expect(NotificationType::TopupSuccess->value)->toBe('topup_success');
    expect(NotificationType::TopupFailed->value)->toBe('topup_failed');
    expect(NotificationType::RideWalletPayment->value)->toBe('ride_wallet_payment');
    expect(NotificationType::WalletRefund->value)->toBe('wallet_refund');
    expect(NotificationType::PayoutApproved->value)->toBe('payout_approved');
    expect(NotificationType::PayoutPaid->value)->toBe('payout_paid');
    expect(NotificationType::PayoutFailed->value)->toBe('payout_failed');
});

test('WalletTopupNotification formats correctly for success', function () {
    $notification = new WalletTopupNotification(50000, true);
    $data = $notification->toArray(new stdClass);

    expect($data['type'])->toBe('topup_success');
    expect($data['amount_kobo'])->toBe(50000);
    expect($data['title'])->toBe('Top-up Successful');
});

test('PayoutStatusNotification formats correctly for failure', function () {
    $notification = new PayoutStatusNotification(100000, NotificationType::PayoutFailed, 'Bank error');
    $data = $notification->toArray(new stdClass);

    expect($data['type'])->toBe('payout_failed');
    expect($data['body'])->toContain('failed');
    expect($data['body'])->toContain('Bank error');
});
