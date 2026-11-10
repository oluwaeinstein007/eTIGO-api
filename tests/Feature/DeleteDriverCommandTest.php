<?php

use App\Enums\DriverStatus;
use App\Enums\WalletTransactionStatus;
use App\Models\Account;
use App\Models\Driver;
use App\Models\OfflineTripFlag;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WalletTransaction;

it('deletes a passenger whose wallet account has wallet transactions', function () {
    $passenger = User::factory()->passenger()->create([
        'phone' => null,
        'email' => 'wallet-owner@etigo.ng',
    ]);

    $account = Account::factory()->passengerWallet()->create([
        'owner_type' => $passenger->getMorphClass(),
        'owner_id' => $passenger->id,
    ]);

    $transaction = WalletTransaction::create([
        'account_id' => $account->id,
        'reference' => 'TOPUP-DELETE01',
        'amount' => 50000,
        'status' => WalletTransactionStatus::Completed,
    ]);

    $this->artisan('driver:delete', ['identifier' => 'wallet-owner@etigo.ng', '--force' => true])
        ->expectsOutputToContain('Account [wallet-owner@etigo.ng] has been permanently deleted.')
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $passenger->id]);
    $this->assertDatabaseMissing('accounts', ['id' => $account->id]);
    $this->assertDatabaseMissing('wallet_transactions', ['id' => $transaction->id]);
});

it('deletes a passenger referenced by an offline trip flag on another ride', function () {
    $passenger = User::factory()->passenger()->create(['email' => 'flagged-passenger@etigo.ng']);
    $flag = OfflineTripFlag::factory()->create(['passenger_id' => $passenger->id]);

    $this->artisan('driver:delete', ['identifier' => 'flagged-passenger@etigo.ng', '--force' => true])
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $passenger->id]);
    $this->assertDatabaseHas('offline_trip_flags', ['id' => $flag->id, 'passenger_id' => null]);
});

it('successfully deletes a driver account by phone number with --force', function () {
    $user = User::factory()->driver()->create([
        'phone' => '+2348011223344',
    ]);
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
        'status' => DriverStatus::PendingReview,
    ]);

    $this->artisan('driver:delete', ['identifier' => '+2348011223344', '--force' => true])
        ->expectsOutputToContain('Driver account [+2348011223344] has been permanently deleted.')
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
});

it('successfully deletes a driver account by email', function () {
    $user = User::factory()->driver()->create([
        'email' => 'driver-to-delete@etigo.ng',
    ]);
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
    ]);

    $this->artisan('driver:delete', ['identifier' => 'driver-to-delete@etigo.ng', '--force' => true])
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
});

it('successfully deletes a driver account by driver UUID', function () {
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
    ]);

    $this->artisan('driver:delete', ['identifier' => $driver->id, '--force' => true])
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
});

it('detaches vehicle when deleting driver', function () {
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
    ]);

    $vehicle = Vehicle::factory()->create([
        'driver_id' => $driver->id,
    ]);

    $this->artisan('driver:delete', ['identifier' => $user->phone, '--force' => true])
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('drivers', ['id' => $driver->id]);
    $this->assertDatabaseHas('vehicles', [
        'id' => $vehicle->id,
        'driver_id' => null,
    ]);
});

it('aborts deletion if user cancels confirmation', function () {
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create([
        'user_id' => $user->id,
    ]);

    $this->artisan('driver:delete', ['identifier' => $user->phone])
        ->expectsConfirmation("Are you sure you want to permanently delete this account ({$user->phone})?", 'no')
        ->expectsOutputToContain('Operation cancelled.')
        ->assertSuccessful();

    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('drivers', ['id' => $driver->id]);
});

it('fails when driver identifier does not exist', function () {
    $this->artisan('driver:delete', ['identifier' => '+2340000000000', '--force' => true])
        ->expectsOutputToContain('No user found matching identifier: +2340000000000')
        ->assertFailed();
});

it('successfully deletes a passenger account with existing rides by email', function () {
    $passenger = User::factory()->passenger()->create([
        'phone' => null,
        'email' => 'baggrbot@gmail.com',
    ]);

    $ride = Ride::factory()->create([
        'passenger_id' => $passenger->id,
    ]);

    $this->artisan('driver:delete', ['identifier' => 'baggrbot@gmail.com', '--force' => true])
        ->expectsOutputToContain('Account [baggrbot@gmail.com] has been permanently deleted.')
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $passenger->id]);
    $this->assertDatabaseMissing('rides', ['id' => $ride->id]);
});

it('works using user:delete alias and confirms with email when phone is null', function () {
    $user = User::factory()->passenger()->create([
        'phone' => null,
        'email' => 'passenger-test@etigo.ng',
    ]);

    $this->artisan('user:delete', ['identifier' => 'passenger-test@etigo.ng'])
        ->expectsConfirmation('Are you sure you want to permanently delete this account (passenger-test@etigo.ng)?', 'yes')
        ->expectsOutputToContain('Account [passenger-test@etigo.ng] has been permanently deleted.')
        ->assertSuccessful();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});
