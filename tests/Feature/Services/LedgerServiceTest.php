<?php

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Models\Account;
use App\Services\LedgerService;

beforeEach(function () {
    $this->ledgerService = app(LedgerService::class);

    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    $this->accountA = Account::factory()->passengerWallet()->withBalance(500000)->create();
    $this->accountB = Account::where('type', AccountType::PlatformCommission)
        ->where('owner_type', 'system')
        ->first();
});

test('postJournal creates balanced journal entries', function () {
    $journal = $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 100000],
    ], [
        'description' => 'Test transfer',
    ]);

    expect($journal)->not->toBeNull();
    expect($journal->entries)->toHaveCount(2);
    expect($journal->isBalanced())->toBeTrue();

    $this->accountA->refresh();
    expect($this->accountA->balance)->toBe(400000);
});

test('postJournal rejects unbalanced entries', function () {
    $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 50000],
    ], [
        'description' => 'Unbalanced',
    ]);
})->throws(\DomainException::class, 'does not balance');

test('postJournal rejects zero or negative amounts', function () {
    $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 0],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 0],
    ], [
        'description' => 'Zero amount',
    ]);
})->throws(\DomainException::class, 'must be positive');

test('postJournal is idempotent with idempotency_key', function () {
    $options = [
        'description' => 'Idempotent test',
        'idempotency_key' => 'test-key-123',
    ];

    $lines = [
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 50000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 50000],
    ];

    $journal1 = $this->ledgerService->postJournal($lines, $options);
    $journal2 = $this->ledgerService->postJournal($lines, $options);

    expect($journal1->id)->toBe($journal2->id);
    expect($this->accountA->refresh()->balance)->toBe(450000);
});

test('postJournal rejects posting to frozen accounts', function () {
    $this->accountA->update(['status' => AccountStatus::Frozen]);

    $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 10000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 10000],
    ], [
        'description' => 'Frozen account test',
    ]);
})->throws(\DomainException::class, 'frozen');

test('postJournal updates running balance on entries', function () {
    $journal = $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 100000],
    ], [
        'description' => 'Running balance test',
    ]);

    $debitEntry = $journal->entries()->where('account_id', $this->accountA->id)->first();
    expect($debitEntry->running_balance)->toBe(400000);
});

test('reverse creates counter-journal', function () {
    $original = $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 75000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 75000],
    ], [
        'description' => 'Original journal',
    ]);

    $reversal = $this->ledgerService->reverse($original);

    expect($reversal->idempotency_key)->toBe("REV-{$original->reference}");
    expect($reversal->isBalanced())->toBeTrue();
    expect($this->accountA->refresh()->balance)->toBe(500000);
});

test('reverse is idempotent', function () {
    $original = $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 50000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 50000],
    ], [
        'description' => 'To reverse',
    ]);

    $rev1 = $this->ledgerService->reverse($original);
    $rev2 = $this->ledgerService->reverse($original);

    expect($rev1->id)->toBe($rev2->id);
});

test('getBalance returns correct breakdown', function () {
    $balance = $this->ledgerService->getBalance($this->accountA);

    expect($balance['balance'])->toBe(500000);
    expect($balance['held'])->toBe(0);
    expect($balance['available'])->toBe(500000);
});

test('findOrCreateAccount creates account if not exists', function () {
    $account = $this->ledgerService->findOrCreateAccount(
        'App\\Models\\User',
        'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        AccountType::PassengerWallet,
    );

    expect($account)->toBeInstanceOf(Account::class);
    expect($account->balance)->toBe(0);
    expect($account->type)->toBe(AccountType::PassengerWallet);
});

test('findOrCreateAccount returns existing account', function () {
    $existing = $this->ledgerService->findOrCreateAccount(
        $this->accountA->owner_type,
        $this->accountA->owner_id,
        AccountType::PassengerWallet,
    );

    expect($existing->id)->toBe($this->accountA->id);
});

test('systemAccount returns seeded system account', function () {
    $platform = $this->ledgerService->systemAccount(AccountType::PlatformCommission);
    expect($platform->owner_type)->toBe('system');
    expect($platform->type)->toBe(AccountType::PlatformCommission);
});

test('balance_version increments with each posting', function () {
    expect($this->accountA->balance_version)->toBe(0);

    $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 10000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 10000],
    ], ['description' => 'Version bump 1']);

    expect($this->accountA->refresh()->balance_version)->toBe(1);

    $this->ledgerService->postJournal([
        ['account_id' => $this->accountA->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 10000],
        ['account_id' => $this->accountB->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 10000],
    ], ['description' => 'Version bump 2']);

    expect($this->accountA->refresh()->balance_version)->toBe(2);
});
