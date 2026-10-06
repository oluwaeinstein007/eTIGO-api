<?php

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;

it('allows approved driver with vehicle to go online', function () {
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->approved()->for($user)->create();
    Vehicle::factory()->for($driver)->create();
    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/toggle-online');

    $response->assertOk()
        ->assertJson(['message' => 'You are now online.'])
        ->assertJsonPath('driver.is_online', true);
});

it('allows online driver to go offline', function () {
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->approved()->for($user)->create(['is_online' => true]);
    Vehicle::factory()->for($driver)->create();
    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/toggle-online');

    $response->assertOk()
        ->assertJson(['message' => 'You are now offline.'])
        ->assertJsonPath('driver.is_online', false);
});

it('rejects unapproved driver from going online', function () {
    $user = User::factory()->driver()->create();
    Driver::factory()->for($user)->create(['status' => DriverStatus::PendingReview]);
    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/toggle-online');

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot go online.']);
});

it('rejects suspended driver from going online', function () {
    $user = User::factory()->driver()->create();
    Driver::factory()->for($user)->create(['status' => DriverStatus::Suspended]);
    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/toggle-online');

    $response->assertStatus(422)
        ->assertJson(['message' => 'Cannot go online.']);
});

it('rejects driver without vehicle from going online', function () {
    $user = User::factory()->driver()->create();
    Driver::factory()->approved()->for($user)->create();
    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/toggle-online');

    $response->assertStatus(422)
        ->assertJsonPath('reasons.0', 'No vehicle registered.');
});
