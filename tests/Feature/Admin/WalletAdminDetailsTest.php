<?php

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Enums\TransactionType;
use App\Enums\WalletTransactionStatus;
use App\Models\Account;
use App\Models\Hold;
use App\Models\Journal;
use App\Models\Ride;
use App\Models\User;
use App\Models\VehicleClass;
use App\Models\WalletTransaction;
use App\Services\LedgerService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->finance = User::factory()->finance()->create();
    $this->token = $this->finance->createToken('auth', ['admin'])->plainTextToken;

    $this->passenger = User::factory()->passenger()->create([
        'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '+2348031234567', 'email' => 'jane@example.com',
    ]);
    $this->wallet = Account::factory()->passengerWallet()->create(['owner_id' => $this->passenger->id]);
    $this->ledger = app(LedgerService::class);
});

function postToWallet(int $kobo, string $key, string $direction = 'credit', array $metadata = []): void
{
    $clearing = test()->ledger->systemAccount(AccountType::PspClearing);
    $lines = $direction === 'credit'
        ? [['account_id' => $clearing->id, 'type' => 'debit', 'amount' => $kobo], ['account_id' => test()->wallet->id, 'type' => 'credit', 'amount' => $kobo]]
        : [['account_id' => test()->wallet->id, 'type' => 'debit', 'amount' => $kobo], ['account_id' => $clearing->id, 'type' => 'credit', 'amount' => $kobo]];

    test()->ledger->postJournal($lines, ['description' => "Test {$key}", 'idempotency_key' => $key, 'metadata' => $metadata]);
}

it('shows the passenger, last activity and held amount on the list', function () {
    postToWallet(500000, 'topup-ref-1');
    Hold::factory()->create(['account_id' => $this->wallet->id, 'amount' => 120000]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/wallets?search=jane%20doe')
        ->assertOk()
        ->assertJsonCount(1, 'wallets')
        ->assertJsonPath('wallets.0.owner.name', 'Jane Doe')
        ->assertJsonPath('wallets.0.owner.phone', '+2348031234567')
        ->assertJsonPath('wallets.0.owner.email', 'jane@example.com')
        ->assertJsonPath('wallets.0.owner.reference_number', $this->passenger->reference_number)
        ->assertJsonPath('wallets.0.held', 120000)
        ->assertJsonPath('wallets.0.available_balance', 380000)
        ->assertJsonPath('wallets.0.last_activity_at', fn ($at) => $at !== null);
});

it('shows each hold with its ride', function () {
    $ride = Ride::factory()->create([
        'passenger_id' => $this->passenger->id,
        'vehicle_class_id' => VehicleClass::factory()->create(['name' => 'Comfort'])->id,
        'pickup_address' => 'Wuse 2',
        'destination_address' => 'Garki',
    ]);
    Hold::factory()->create(['account_id' => $this->wallet->id, 'ride_id' => $ride->id, 'amount' => 250000]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}")
        ->assertOk()
        ->assertJsonPath('wallet.owner.name', 'Jane Doe')
        ->assertJsonPath('active_holds.0.ride.reference_number', $ride->reference_number)
        ->assertJsonPath('active_holds.0.ride.vehicle_class', 'Comfort')
        ->assertJsonPath('active_holds.0.ride.pickup_address', 'Wuse 2')
        ->assertJsonPath('active_holds.0.ride.status', $ride->status->value)
        ->assertJsonPath('permissions.can_freeze', true);
});

it('tells a read-only admin they cannot freeze', function () {
    $support = User::factory()->admin(AdminRole::Support)->create();

    $this->withToken($support->createToken('auth', ['admin'])->plainTextToken)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}")
        ->assertOk()
        ->assertJsonPath('permissions.can_freeze', false);
});

it('records who froze the wallet, when and why', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/wallets/{$this->wallet->id}/freeze", ['reason' => 'Chargeback investigation opened.'])
        ->assertOk()
        ->assertJsonPath('wallet.status', 'frozen')
        ->assertJsonPath('wallet.frozen.reason', 'Chargeback investigation opened.')
        ->assertJsonPath('wallet.frozen.frozen_at', '2026-10-17T09:00:00+00:00')
        ->assertJsonPath('wallet.frozen.frozen_by.id', $this->finance->id)
        ->assertJsonPath('wallet.frozen.frozen_by.role', 'finance');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}")
        ->assertJsonPath('wallet.frozen.reason', 'Chargeback investigation opened.');

    $this->withToken($this->token)
        ->postJson("/api/v1/admin/wallets/{$this->wallet->id}/unfreeze", ['reason' => 'Investigation closed in their favour.'])
        ->assertOk()
        ->assertJsonMissingPath('wallet.frozen');

    expect($this->wallet->fresh())->frozen_reason->toBeNull()->frozen_by_admin_id->toBeNull();

    Carbon::setTestNow();
});

it('returns the audit trail of staff changes', function () {
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/wallets/{$this->wallet->id}/freeze", ['reason' => 'Chargeback investigation opened.']);
    $this->withToken($this->token)
        ->postJson("/api/v1/admin/wallets/{$this->wallet->id}/unfreeze", ['reason' => 'Investigation closed in their favour.']);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}/audit-trail")
        ->assertOk()
        ->assertJsonCount(2, 'entries')
        ->assertJsonPath('entries.1.description', 'Wallet frozen')
        ->assertJsonPath('entries.1.reason', 'Chargeback investigation opened.')
        ->assertJsonPath('entries.1.actor.role', 'finance')
        ->assertJsonPath('entries.0.description', 'Wallet unfrozen');
});

it('pages through every transaction with its type and status', function () {
    $ride = Ride::factory()->create(['passenger_id' => $this->passenger->id]);

    postToWallet(500000, 'topup-ref-1');
    postToWallet(150000, "ride-settlement-{$ride->id}", 'debit', ['ride_id' => $ride->id]);
    postToWallet(20000, "wallet-tip-{$ride->id}", 'debit', ['ride_id' => $ride->id]);
    WalletTransaction::create(['account_id' => $this->wallet->id, 'reference' => 'TOPUP-PENDING', 'amount' => 100000, 'status' => WalletTransactionStatus::Pending]);
    WalletTransaction::create(['account_id' => $this->wallet->id, 'reference' => 'TOPUP-FAILED', 'amount' => 90000, 'status' => WalletTransactionStatus::Failed]);
    WalletTransaction::create(['account_id' => $this->wallet->id, 'reference' => 'TOPUP-DONE', 'amount' => 500000, 'status' => WalletTransactionStatus::Completed]);

    $all = $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}/transactions?per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.total', 5)
        ->json('transactions');

    expect(collect($all)->pluck('status')->countBy()->all())->toEqual(['success' => 3, 'pending' => 1, 'failed' => 1]);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}/transactions?type=ride_payment")
        ->assertJsonCount(1, 'transactions')
        ->assertJsonPath('transactions.0.type.label', 'Ride Payment')
        ->assertJsonPath('transactions.0.direction', 'debit')
        ->assertJsonPath('transactions.0.ride_reference_number', $ride->reference_number);

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}/transactions?status=failed")
        ->assertJsonCount(1, 'transactions')
        ->assertJsonPath('transactions.0.reference', 'TOPUP-FAILED')
        ->assertJsonPath('transactions.0.type.value', 'top_up');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}/transactions?search=".$ride->reference_number)
        ->assertJsonCount(2, 'transactions');

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}/transactions?per_page=2")
        ->assertJsonCount(2, 'transactions')
        ->assertJsonPath('meta.last_page', 3);
});

it('filters transactions by Lagos calendar day', function () {
    // 23:30 UTC on the 15th is already the 16th in Lagos.
    Carbon::setTestNow(Carbon::parse('2026-10-15 23:30:00', 'UTC'));
    postToWallet(1000, 'topup-late');
    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
    postToWallet(2000, 'topup-midday');
    Carbon::setTestNow();

    $this->withToken($this->token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}/transactions?date_from=2026-10-16&date_to=2026-10-16")
        ->assertJsonCount(1, 'transactions')
        ->assertJsonPath('transactions.0.amount', 1000);
});

it('only opens passenger wallets', function () {
    $driverLedger = Account::factory()->driverEarningsPending()->create();

    $this->withToken($this->token)->getJson("/api/v1/admin/wallets/{$driverLedger->id}")->assertNotFound();
    $this->withToken($this->token)->getJson("/api/v1/admin/wallets/{$driverLedger->id}/transactions")->assertNotFound();
});

it('stores the transaction type on each journal', function () {
    postToWallet(1000, 'topup-abc');

    expect(Journal::where('idempotency_key', 'topup-abc')->value('type'))->toBe(TransactionType::TopUp);
});

it('classifies journals from their key prefix', function (string $key, ?TransactionType $type) {
    expect(TransactionType::fromJournalKey($key))->toBe($type);
})->with([
    ['topup-PSK_123', TransactionType::TopUp],
    ['balance-due-unapplied-REF', TransactionType::TopUp],
    ['balance-due-0199', TransactionType::BalanceDue],
    ['ride-settlement-0199', TransactionType::RidePayment],
    ['wallet-tip-0199', TransactionType::Tip],
    ['refund-0199-abcd1234', TransactionType::Refund],
    ['scheduled-cancel-fee-0199', TransactionType::CancellationFee],
    ['REV-JRN-1', TransactionType::Reversal],
    ['something-else', null],
]);

it('keeps statuses the design does not show', function () {
    $this->wallet->update(['status' => AccountStatus::Closed]);

    $this->withToken($this->token)
        ->getJson('/api/v1/admin/wallets?status=closed')
        ->assertJsonPath('wallets.0.status', 'closed');
});

it('lets operations view wallets but not freeze them', function () {
    $operations = User::factory()->admin(AdminRole::Operations)->create();
    $token = $operations->createToken('auth', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/wallets')->assertOk();
    $this->withToken($token)->getJson("/api/v1/admin/wallets/{$this->wallet->id}/transactions")->assertOk();
    $this->withToken($token)->getJson("/api/v1/admin/wallets/{$this->wallet->id}/audit-trail")->assertOk();
    $this->withToken($token)
        ->getJson("/api/v1/admin/wallets/{$this->wallet->id}")
        ->assertOk()
        ->assertJsonPath('permissions.can_freeze', false);

    $this->withToken($token)
        ->postJson("/api/v1/admin/wallets/{$this->wallet->id}/freeze", ['reason' => 'Operations should not be able to do this.'])
        ->assertForbidden();

    expect($this->wallet->fresh()->status)->toBe(AccountStatus::Active);
});

it('keeps wallets closed to safety operators', function () {
    $safety = User::factory()->safetyOperator()->create();

    $this->withToken($safety->createToken('auth', ['admin'])->plainTextToken)
        ->getJson('/api/v1/admin/wallets')
        ->assertForbidden();
});
