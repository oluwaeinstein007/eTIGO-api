<?php

use App\Contracts\WalletGateway;
use App\Enums\UserType;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['wallet.paystack.secret_key' => 'sk_test_fake_key']);
    $this->driverUser = User::factory()->create(['type' => UserType::Driver]);
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->driverUser->id]);
    $this->token = $this->driverUser->createToken('test', ['driver'])->plainTextToken;
});

test('driver can fetch supported banks list', function () {
    Cache::flush();

    $mockGateway = Mockery::mock(WalletGateway::class);
    $mockGateway->shouldReceive('listBanks')
        ->once()
        ->andReturn([
            ['id' => 1, 'code' => '044', 'name' => 'Access Bank'],
            ['id' => 2, 'code' => '058', 'name' => 'Guaranty Trust Bank'],
        ]);
    $this->app->instance(WalletGateway::class, $mockGateway);

    $response = $this->withToken($this->token)->getJson('/api/v1/driver/bank-account/banks');

    $response->assertOk()
        ->assertJsonCount(2, 'banks')
        ->assertJsonPath('banks.0.name', 'Access Bank')
        ->assertJsonPath('banks.1.code', '058');

    // Calling a second time should use cache
    $secondResponse = $this->withToken($this->token)->getJson('/api/v1/driver/bank-account/banks');
    $secondResponse->assertOk()
        ->assertJsonCount(2, 'banks');
});

test('driver bank resolve validates bank_code and account_number', function () {
    $response = $this->withToken($this->token)->postJson('/api/v1/driver/bank-account/resolve', [
        'bank_code' => '',
        'account_number' => '123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['bank_code', 'account_number']);
});

test('driver can resolve bank account details successfully', function () {
    $mockGateway = Mockery::mock(WalletGateway::class);
    $mockGateway->shouldReceive('resolveAccountNumber')
        ->with('0123456789', '058')
        ->once()
        ->andReturn([
            'account_number' => '0123456789',
            'account_name' => 'TUNDE BAKARE',
        ]);
    $this->app->instance(WalletGateway::class, $mockGateway);

    $response = $this->withToken($this->token)->postJson('/api/v1/driver/bank-account/resolve', [
        'bank_code' => '058',
        'account_number' => '0123456789',
    ]);

    $response->assertOk()
        ->assertJson([
            'bank_code' => '058',
            'account_number' => '0123456789',
            'account_name' => 'TUNDE BAKARE',
        ]);
});

test('driver bank resolve returns 422 if account cannot be resolved', function () {
    $mockGateway = Mockery::mock(WalletGateway::class);
    $mockGateway->shouldReceive('resolveAccountNumber')
        ->with('0000000000', '058')
        ->once()
        ->andThrow(new RuntimeException('Account not found'));
    $this->app->instance(WalletGateway::class, $mockGateway);

    $response = $this->withToken($this->token)->postJson('/api/v1/driver/bank-account/resolve', [
        'bank_code' => '058',
        'account_number' => '0000000000',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Unable to resolve bank account details. Please check the account number and bank.',
        ]);
});

test('driver can store verified bank account', function () {
    $mockGateway = Mockery::mock(WalletGateway::class);
    $mockGateway->shouldReceive('resolveAccountNumber')
        ->with('0123456789', '058')
        ->once()
        ->andReturn([
            'account_number' => '0123456789',
            'account_name' => 'TUNDE BAKARE',
        ]);
    $this->app->instance(WalletGateway::class, $mockGateway);

    $response = $this->withToken($this->token)->postJson('/api/v1/driver/bank-account', [
        'bank_code' => '058',
        'account_number' => '0123456789',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('bank_account.bank_code', '058')
        ->assertJsonPath('bank_account.account_number', '******6789')
        ->assertJsonPath('bank_account.account_name', 'TUNDE BAKARE')
        ->assertJsonPath('bank_account.is_verified', true)
        ->assertJsonPath('bank_account.is_primary', true);

    // Verify GET /api/v1/driver/bank-account returns this primary bank account
    $showResponse = $this->withToken($this->token)->getJson('/api/v1/driver/bank-account');
    $showResponse->assertOk()
        ->assertJsonPath('bank_account.account_number', '******6789')
        ->assertJsonPath('bank_account.account_name', 'TUNDE BAKARE');
});
