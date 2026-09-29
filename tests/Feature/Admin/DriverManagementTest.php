<?php

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\User;

it('lists all drivers for admin', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    Driver::factory()->count(3)->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/drivers');

    $response->assertOk()
        ->assertJsonCount(3, 'drivers')
        ->assertJsonStructure(['drivers', 'meta']);
});

it('lists pending review drivers', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    Driver::factory()->count(2)->create();
    Driver::factory()->approved()->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/drivers/pending');

    $response->assertOk()
        ->assertJsonCount(2, 'drivers');
});

it('shows a single driver with details', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->create();

    $response = $this->withToken($token)
        ->getJson("/api/v1/admin/drivers/{$driver->id}");

    $response->assertOk()
        ->assertJsonStructure(['driver' => ['id', 'status', 'user']]);
});

it('allows admin to approve a driver', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->create();

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/review", [
            'action' => 'approve',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Driver approved successfully.']);

    expect($driver->fresh()->status)->toBe(DriverStatus::Approved);
});

it('allows admin to reject a driver with reason', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->create();

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/review", [
            'action' => 'reject',
            'rejection_reason' => 'Documents are blurry and unreadable.',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Driver rejected.']);

    $driver->refresh();
    expect($driver->status)->toBe(DriverStatus::Rejected);
    expect($driver->rejection_reason)->toBe('Documents are blurry and unreadable.');
});

it('requires rejection reason when rejecting', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->create();

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/review", [
            'action' => 'reject',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('rejection_reason');
});

it('allows admin to suspend and reactivate a driver', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->approved()->create(['is_online' => true]);

    $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/suspend")
        ->assertOk();

    $driver->refresh();
    expect($driver->status)->toBe(DriverStatus::Suspended);
    expect($driver->is_online)->toBeFalse();

    $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/reactivate")
        ->assertOk();

    expect($driver->fresh()->status)->toBe(DriverStatus::Approved);
});

it('rejects review of a non-pending driver', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->approved()->create();

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/review", [
            'action' => 'approve',
        ]);

    $response->assertStatus(422)
        ->assertJson(['message' => 'Driver can only be reviewed when in pending review status.']);
});

it('rejects suspending a non-approved driver', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->create();

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/suspend");

    $response->assertStatus(422)
        ->assertJson(['message' => 'Only approved drivers can be suspended.']);
});

it('rejects reactivating a non-suspended driver', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->approved()->create();

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/reactivate");

    $response->assertStatus(422)
        ->assertJson(['message' => 'Only suspended drivers can be reactivated.']);
});

it('prevents non-admin from accessing admin routes', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/drivers');

    $response->assertForbidden();
});
