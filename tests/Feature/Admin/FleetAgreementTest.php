<?php

use App\Enums\FleetAgreementStatus;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\User;
use App\Models\Vehicle;

function adminToken(): array
{
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    return [$admin, $token];
}

it('lists fleet agreements', function () {
    [$admin, $token] = adminToken();

    FleetAgreement::factory()->count(3)->create(['created_by_admin_id' => $admin->id]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/fleet-agreements');

    $response->assertOk()
        ->assertJsonCount(3, 'agreements')
        ->assertJsonStructure(['agreements', 'meta']);
});

it('filters fleet agreements by status', function () {
    [$admin, $token] = adminToken();

    FleetAgreement::factory()->count(2)->create(['created_by_admin_id' => $admin->id]);
    FleetAgreement::factory()->terminated()->create(['created_by_admin_id' => $admin->id]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/fleet-agreements?status=active');

    $response->assertOk()
        ->assertJsonCount(2, 'agreements');
});

it('creates a fleet agreement', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/fleet-agreements', [
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'daily_remittance_target' => 40000,
            'total_vehicle_cost' => 5000000,
            'agreement_start_date' => now()->toDateString(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('agreement.driver_id', $driver->id)
        ->assertJsonPath('agreement.status', 'active')
        ->assertJsonPath('agreement.daily_remittance_target', '40000.00');

    $this->assertDatabaseHas('fleet_agreements', [
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
    ]);
});

it('rejects creating agreement for non-fleet vehicle', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->create(['driver_id' => $driver->id]);

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/fleet-agreements', [
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'daily_remittance_target' => 40000,
            'total_vehicle_cost' => 5000000,
            'agreement_start_date' => now()->toDateString(),
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Vehicle must be marked as fleet before creating an agreement.');
});

it('rejects duplicate active agreement for same driver', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle1 = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle1->id,
        'created_by_admin_id' => $admin->id,
    ]);

    $vehicle2 = Vehicle::factory()->fleet()->create();

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/fleet-agreements', [
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle2->id,
            'daily_remittance_target' => 40000,
            'total_vehicle_cost' => 5000000,
            'agreement_start_date' => now()->toDateString(),
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Driver already has an active fleet agreement.');
});

it('shows a fleet agreement with recent remittances', function () {
    [$admin, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['created_by_admin_id' => $admin->id]);

    $response = $this->withToken($token)
        ->getJson("/api/v1/admin/fleet-agreements/{$agreement->id}");

    $response->assertOk()
        ->assertJsonPath('agreement.id', $agreement->id);
});

it('updates daily remittance target on active agreement', function () {
    [$admin, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['created_by_admin_id' => $admin->id]);

    $response = $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-agreements/{$agreement->id}", [
            'daily_remittance_target' => 45000,
        ]);

    $response->assertOk()
        ->assertJsonPath('agreement.daily_remittance_target', '45000.00');
});

it('rejects updating terminated agreement', function () {
    [$admin, $token] = adminToken();

    $agreement = FleetAgreement::factory()->terminated()->create(['created_by_admin_id' => $admin->id]);

    $response = $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-agreements/{$agreement->id}", [
            'daily_remittance_target' => 45000,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Only active agreements can be updated.');
});

it('terminates a fleet agreement', function () {
    [$admin, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['created_by_admin_id' => $admin->id]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/terminate", [
            'reason' => 'Driver violated terms',
        ]);

    $response->assertOk()
        ->assertJsonPath('agreement.status', 'terminated');

    $this->assertDatabaseHas('fleet_agreements', [
        'id' => $agreement->id,
        'status' => 'terminated',
    ]);
});

it('pauses and resumes a fleet agreement', function () {
    [$admin, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['created_by_admin_id' => $admin->id]);

    $pauseResponse = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/pause");

    $pauseResponse->assertOk()
        ->assertJsonPath('agreement.status', 'paused');

    $resumeResponse = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/resume");

    $resumeResponse->assertOk()
        ->assertJsonPath('agreement.status', 'active');
});

it('exposes total_vehicle_cost to admin users', function () {
    [$admin, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['created_by_admin_id' => $admin->id]);

    $response = $this->withToken($token)
        ->getJson("/api/v1/admin/fleet-agreements/{$agreement->id}");

    $response->assertOk()
        ->assertJsonPath('agreement.total_vehicle_cost', $agreement->total_vehicle_cost);
});

it('hides total_vehicle_cost from driver users in resource', function () {
    $user = User::factory()->driver()->create();
    $driver = Driver::factory()->create(['user_id' => $user->id]);
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $admin = User::factory()->admin()->create();

    FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'created_by_admin_id' => $admin->id,
    ]);

    $token = $user->createToken('driver-auth', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/fleet-agreement');

    $response->assertOk()
        ->assertJsonMissingPath('agreement.total_vehicle_cost');
});

it('creates audit logs for fleet agreement operations', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);

    $this->withToken($token)
        ->postJson('/api/v1/admin/fleet-agreements', [
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'daily_remittance_target' => 40000,
            'total_vehicle_cost' => 5000000,
            'agreement_start_date' => now()->toDateString(),
        ]);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'fleet_agreement.created',
        'actor_id' => $admin->id,
    ]);
});
