<?php

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\SystemAccountSeeder']);

    $this->admin = User::factory()->admin()->create();
    $this->adminToken = $this->admin->createToken('auth', ['admin'])->plainTextToken;
});

test('admin can list all wallets', function () {
    Account::factory()->passengerWallet()->count(3)->create();

    $response = $this->withToken($this->adminToken)
        ->getJson('/api/v1/admin/wallets');

    $response->assertOk()
        ->assertJsonStructure(['wallets', 'meta']);
});

test('admin can view a specific wallet', function () {
    $account = Account::factory()->passengerWallet()->create();

    $response = $this->withToken($this->adminToken)
        ->getJson("/api/v1/admin/wallets/{$account->id}");

    $response->assertOk()
        ->assertJsonPath('wallet.id', $account->id);
});

test('admin can freeze a wallet', function () {
    $account = Account::factory()->passengerWallet()->create();

    $response = $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/wallets/{$account->id}/freeze", [
            'reason' => 'Suspicious activity detected on this account',
        ]);

    $response->assertOk();

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::Frozen);
});

test('admin can unfreeze a frozen wallet', function () {
    $account = Account::factory()->passengerWallet()->frozen()->create();

    $response = $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/wallets/{$account->id}/unfreeze", [
            'reason' => 'Issue resolved after investigation',
        ]);

    $response->assertOk();

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::Active);
});

test('freeze requires a reason', function () {
    $account = Account::factory()->passengerWallet()->create();

    $response = $this->withToken($this->adminToken)
        ->postJson("/api/v1/admin/wallets/{$account->id}/freeze", []);

    $response->assertUnprocessable();
});

test('non-admin cannot access admin wallet endpoints', function () {
    $user = User::factory()->create();
    $token = $user->createToken('auth', ['passenger'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/admin/wallets')
        ->assertForbidden();
});
