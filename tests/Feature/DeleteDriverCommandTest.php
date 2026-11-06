<?php

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;

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
