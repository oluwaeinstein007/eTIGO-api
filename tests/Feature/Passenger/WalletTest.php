<?php

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Enums\UserType;
use App\Models\User;
use App\Services\LedgerService;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    $this->passenger = User::factory()->create(['type' => UserType::Passenger]);
    $this->token = $this->passenger->createToken('auth', ['passenger'])->plainTextToken;
});

test('passenger can view wallet balance', function () {
    $ledgerService = app(LedgerService::class);
    $account = $ledgerService->findOrCreateAccount(
        $this->passenger->getMorphClass(),
        $this->passenger->id,
        AccountType::PassengerWallet,
    );

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/passenger/wallet');

    $response->assertOk()
        ->assertJsonStructure([
            'wallet' => ['id', 'currency', 'status', 'balance', 'available', 'held'],
        ]);
});

test('passenger auto-creates wallet on first view', function () {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/passenger/wallet');

    $response->assertOk();
    expect($response->json('wallet.balance'))->toBe(0);
    expect($response->json('wallet.currency'))->toBe('NGN');
});

test('passenger can list transactions', function () {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/passenger/wallet/transactions');

    $response->assertOk()
        ->assertJsonStructure([
            'transactions',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

test('passenger can filter transactions by type', function () {
    $ledgerService = app(LedgerService::class);
    $account = $ledgerService->findOrCreateAccount(
        $this->passenger->getMorphClass(),
        $this->passenger->id,
        AccountType::PassengerWallet,
    );

    $pspAccount = $ledgerService->systemAccount(AccountType::PspClearing);

    $ledgerService->postJournal([
        ['account_id' => $pspAccount->id, 'type' => LedgerEntryType::Debit->value, 'amount' => 100000],
        ['account_id' => $account->id, 'type' => LedgerEntryType::Credit->value, 'amount' => 100000],
    ], ['description' => 'Test topup']);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/passenger/wallet/transactions?type=credit');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

test('unauthenticated user cannot access wallet', function () {
    $this->getJson('/api/v1/passenger/wallet')
        ->assertUnauthorized();
});
