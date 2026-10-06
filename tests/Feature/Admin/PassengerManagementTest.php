<?php

use App\Enums\UserType;
use App\Models\User;

it('lists passengers for admin', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    User::factory()->count(3)->create(['type' => UserType::Passenger]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/passengers');

    $response->assertOk()
        ->assertJsonCount(3, 'passengers')
        ->assertJsonStructure(['passengers', 'meta']);
});

it('searches passengers by name', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    User::factory()->create(['type' => UserType::Passenger, 'first_name' => 'Oluwaseun']);
    User::factory()->create(['type' => UserType::Passenger, 'first_name' => 'Adebayo']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/passengers?search=Oluwaseun');

    $response->assertOk()
        ->assertJsonCount(1, 'passengers');
});

it('filters passengers by active status', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    User::factory()->count(2)->create(['type' => UserType::Passenger, 'is_active' => true]);
    User::factory()->create(['type' => UserType::Passenger, 'is_active' => false]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/passengers?is_active=true');

    $response->assertOk()
        ->assertJsonCount(2, 'passengers');
});

it('shows passenger details', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $passenger = User::factory()->create(['type' => UserType::Passenger]);

    $response = $this->withToken($token)
        ->getJson("/api/v1/admin/passengers/{$passenger->id}");

    $response->assertOk()
        ->assertJsonStructure(['passenger', 'statistics']);
});

it('returns 404 for non-passenger user on show', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = User::factory()->driver()->create();

    $response = $this->withToken($token)
        ->getJson("/api/v1/admin/passengers/{$driver->id}");

    $response->assertNotFound();
});

it('suspends a passenger account', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $passenger = User::factory()->create(['type' => UserType::Passenger, 'is_active' => true]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/passengers/{$passenger->id}/suspend");

    $response->assertOk()
        ->assertJson(['message' => 'Passenger account suspended.']);

    expect($passenger->fresh()->is_active)->toBeFalse();
});

it('rejects suspending already suspended passenger', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $passenger = User::factory()->create(['type' => UserType::Passenger, 'is_active' => false]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/passengers/{$passenger->id}/suspend");

    $response->assertStatus(422);
});

it('reactivates a suspended passenger', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $passenger = User::factory()->create(['type' => UserType::Passenger, 'is_active' => false]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/passengers/{$passenger->id}/reactivate");

    $response->assertOk()
        ->assertJson(['message' => 'Passenger account reactivated.']);

    expect($passenger->fresh()->is_active)->toBeTrue();
});

it('rejects reactivating already active passenger', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $passenger = User::factory()->create(['type' => UserType::Passenger, 'is_active' => true]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/passengers/{$passenger->id}/reactivate");

    $response->assertStatus(422);
});

it('revokes tokens when suspending passenger', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $passenger = User::factory()->create(['type' => UserType::Passenger, 'is_active' => true]);
    $passenger->createToken('passenger-auth', ['passenger']);

    expect($passenger->tokens()->count())->toBe(1);

    $this->withToken($token)
        ->postJson("/api/v1/admin/passengers/{$passenger->id}/suspend");

    expect($passenger->tokens()->count())->toBe(0);
});

it('denies non-admin access to passenger management', function () {
    $passenger = User::factory()->create(['type' => UserType::Passenger]);
    $token = $passenger->createToken('passenger-auth', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/passengers');

    $response->assertForbidden();
});
