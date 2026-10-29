<?php

use App\Models\DailyRemittance;
use App\Models\Driver;
use App\Models\FleetAgreement;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\CommissionService;

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
        ->assertJsonPath('message', 'Driver already has an active or paused fleet agreement.');
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
        ->assertJsonPath('message', 'Only active or paused agreements can be changed.');
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

    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/pause")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $pauseResponse = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/pause", ['reason' => 'Car in for an engine repair']);

    $pauseResponse->assertOk()
        ->assertJsonPath('agreement.status', 'paused')
        ->assertJsonPath('agreement.paused_reason', 'Car in for an engine repair');

    $resumeResponse = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/resume");

    $resumeResponse->assertOk()
        ->assertJsonPath('agreement.status', 'active');

    expect($agreement->fresh()->paused_reason)->toBeNull();
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
        ->assertJsonPath('agreement.total_vehicle_cost', '5000000.00')
        ->assertJsonPath('agreement.progress_percentage', fn ($v) => $v >= 0);
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

it('swaps fleet vehicle with carry-over of remitted amount', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create(['vehicle_ownership_type' => 'fleet_vehicle']);
    $oldVehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $newVehicle = Vehicle::factory()->fleet()->create(['driver_id' => null]);

    $agreement = FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $oldVehicle->id,
        'created_by_admin_id' => $admin->id,
        'total_remitted' => 500000,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/swap", [
            'new_vehicle_id' => $newVehicle->id,
            'reason' => 'Vehicle needs maintenance',
            'carry_over_remitted' => true,
        ]);

    $response->assertOk()
        ->assertJsonPath('agreement.vehicle_id', $newVehicle->id)
        ->assertJsonPath('agreement.total_remitted', '500000.00');

    $this->assertDatabaseHas('fleet_agreements', [
        'id' => $agreement->id,
        'status' => 'terminated',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'fleet_vehicle_swapped',
    ]);
});

it('swaps fleet vehicle without carry-over', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create(['vehicle_ownership_type' => 'fleet_vehicle']);
    $oldVehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $newVehicle = Vehicle::factory()->fleet()->create(['driver_id' => null]);

    $agreement = FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $oldVehicle->id,
        'created_by_admin_id' => $admin->id,
        'total_remitted' => 500000,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/swap", [
            'new_vehicle_id' => $newVehicle->id,
            'reason' => 'Upgrade to newer model',
            'carry_over_remitted' => false,
            'daily_remittance_target' => 45000,
            'total_vehicle_cost' => 6000000,
        ]);

    $response->assertOk()
        ->assertJsonPath('agreement.total_remitted', '0.00')
        ->assertJsonPath('agreement.daily_remittance_target', '45000.00')
        ->assertJsonPath('agreement.total_vehicle_cost', '6000000.00');
});

it('terminates agreement with settlement details', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);

    $agreement = FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'created_by_admin_id' => $admin->id,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/terminate-settle", [
            'reason' => 'Driver quit voluntarily',
            'vehicle_return_status' => 'returned',
            'outstanding_amount' => 150000,
            'settlement_amount' => 50000,
            'settlement_notes' => 'Driver paid partial settlement. Remaining waived per HR decision.',
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('fleet_agreements', [
        'id' => $agreement->id,
        'status' => 'terminated',
        'vehicle_return_status' => 'returned',
        'outstanding_amount' => 150000,
        'settlement_amount' => 50000,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'fleet_agreement.terminated_with_settlement',
    ]);
});

it('excuses a remittance day and adjusts shortfall streak', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);

    $agreement = FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'created_by_admin_id' => $admin->id,
        'shortfall_streak_days' => 3,
    ]);

    $remittance = DailyRemittance::factory()->create([
        'agreement_id' => $agreement->id,
        'driver_id' => $driver->id,
        'settled' => true,
        'remitted_amount' => 20000,
        'target_amount' => 40000,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/remittances/{$remittance->id}/excuse", [
            'reason' => 'vehicle_downtime',
            'notes' => 'Vehicle was in maintenance',
        ]);

    $response->assertOk();

    $remittance->refresh();
    $agreement->refresh();

    expect($remittance->excused_reason)->toBe('vehicle_downtime: Vehicle was in maintenance')
        ->and($agreement->shortfall_streak_days)->toBe(2);
});

it('returns zero commission rate for fleet drivers', function () {
    $driver = Driver::factory()->create(['vehicle_ownership_type' => 'fleet_vehicle']);
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $admin = User::factory()->admin()->create();

    FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'created_by_admin_id' => $admin->id,
    ]);

    $commissionService = app(CommissionService::class);
    $rate = $commissionService->getRate($driver->id);

    expect($rate)->toBe(0.0);

    $result = $commissionService->calculate(500000, $driver->id);
    expect($result['commission'])->toBe(0)
        ->and($result['net_earnings'])->toBe(500000);
});

it('gives each agreement a readable reference and finds it by reference, driver or plate', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create();
    $driver->user->update(['first_name' => 'Tunde', 'last_name' => 'Bakare']);
    $mine = FleetAgreement::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_id' => Vehicle::factory()->fleet()->create(['plate_number' => 'LAG-123-XY'])->id,
    ]);
    FleetAgreement::factory()->create();

    expect($mine->reference_number)->toMatch('/^FA-\d{6}$/');

    foreach ([$mine->reference_number, 'Tunde Bakare', 'lag-123'] as $search) {
        $this->withToken($token)
            ->getJson('/api/v1/admin/fleet-agreements?search='.urlencode($search))
            ->assertOk()
            ->assertJsonCount(1, 'agreements')
            ->assertJsonPath('agreements.0.reference_number', $mine->reference_number);
    }
});

it('filters agreements by vehicle class', function () {
    [, $token] = adminToken();

    $class = VehicleClass::factory()->create();
    $match = FleetAgreement::factory()->create(['vehicle_id' => Vehicle::factory()->fleet()->create(['vehicle_class_id' => $class->id])->id]);
    FleetAgreement::factory()->create();

    $this->withToken($token)
        ->getJson("/api/v1/admin/fleet-agreements?vehicle_class_id={$class->id}")
        ->assertOk()
        ->assertJsonCount(1, 'agreements')
        ->assertJsonPath('agreements.0.id', $match->id)
        ->assertJsonPath('agreements.0.vehicle.vehicle_class.id', $class->id);
});

it('corrects the total vehicle cost and start date with a reason', function () {
    [, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['total_remitted' => 200000]);

    $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-agreements/{$agreement->id}", ['total_vehicle_cost' => 6000000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-agreements/{$agreement->id}", [
            'total_vehicle_cost' => 6000000,
            'agreement_start_date' => now('Africa/Lagos')->addDays(2)->toDateString(),
            'reason' => 'Cost was typed without the last zero',
        ])
        ->assertOk()
        ->assertJsonPath('agreement.total_vehicle_cost', '6000000.00')
        ->assertJsonPath('agreement.agreement_start_date', now('Africa/Lagos')->addDays(2)->toDateString());

    $this->withToken($token)
        ->getJson("/api/v1/admin/fleet-agreements/{$agreement->id}/history")
        ->assertOk()
        ->assertJsonPath('history.0.event', 'fleet_agreement.updated')
        ->assertJsonPath('history.0.reason', 'Cost was typed without the last zero')
        ->assertJsonPath('history.0.changes.total_vehicle_cost.from', '5000000.00');
});

it('refuses a total cost at or below what the driver has paid, on the field', function () {
    [, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['total_remitted' => 300000]);

    $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-agreements/{$agreement->id}", ['total_vehicle_cost' => 300000, 'reason' => 'Typo fix'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('total_vehicle_cost');
});

it('refuses a start date after the first day the driver paid', function () {
    [, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create();
    DailyRemittance::factory()->create([
        'agreement_id' => $agreement->id,
        'driver_id' => $agreement->driver_id,
        'date' => now('Africa/Lagos')->toDateString(),
        'remitted_amount' => 10000,
    ]);

    $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-agreements/{$agreement->id}", [
            'agreement_start_date' => now('Africa/Lagos')->addDays(3)->toDateString(),
            'reason' => 'Wrong date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('agreement_start_date');
});

it('takes the car back and the driver offline when an agreement is terminated', function () {
    [$admin, $token] = adminToken();

    $driver = Driver::factory()->create(['is_online' => true]);
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $agreement = FleetAgreement::factory()->create(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);

    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/terminate", ['reason' => 'Driver left E-tiGo'])
        ->assertOk();

    expect($vehicle->fresh()->driver_id)->toBeNull()
        ->and($driver->fresh()->is_online)->toBeFalse();

    $this->assertDatabaseHas('audit_logs', ['event' => 'fleet_vehicle_unassigned', 'auditable_id' => $vehicle->id, 'actor_id' => $admin->id]);
});

it('takes the car back when an agreement is terminated with a settlement', function () {
    [, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $agreement = FleetAgreement::factory()->create(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);

    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/terminate-settle", [
            'reason' => 'Mutual exit',
            'vehicle_return_status' => 'pending_return',
        ])
        ->assertOk();

    expect($vehicle->fresh()->driver_id)->toBeNull();
});

it('refuses to terminate while the driver is on a trip', function () {
    [, $token] = adminToken();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->fleet()->create(['driver_id' => $driver->id]);
    $agreement = FleetAgreement::factory()->create(['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id]);
    Ride::factory()->inProgress()->create(['driver_id' => $driver->user_id]);

    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/terminate", ['reason' => 'Driver left E-tiGo'])
        ->assertUnprocessable();

    expect($agreement->fresh()->status->value)->toBe('active')
        ->and($vehicle->fresh()->driver_id)->toBe($driver->id);
});

it('refuses to excuse a day twice with a 422', function () {
    [, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create();
    $remittance = DailyRemittance::factory()->create([
        'agreement_id' => $agreement->id,
        'driver_id' => $agreement->driver_id,
        'excused_reason' => 'approved_leave',
    ]);

    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/remittances/{$remittance->id}/excuse", ['reason' => 'approved_leave'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This remittance day is already excused.');
});

it('withdraws an excuse and counts the short day towards the streak again', function () {
    [, $token] = adminToken();

    $agreement = FleetAgreement::factory()->create(['shortfall_streak_days' => 1]);
    $remittance = DailyRemittance::factory()->create([
        'agreement_id' => $agreement->id,
        'driver_id' => $agreement->driver_id,
        'settled' => true,
        'remitted_amount' => 20000,
        'target_amount' => 40000,
        'excused_reason' => 'approved_leave',
    ]);

    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/remittances/{$remittance->id}/withdraw-excuse", ['reason' => 'Leave was not approved'])
        ->assertOk()
        ->assertJsonMissingPath('remittance.excused_reason');

    expect($agreement->fresh()->shortfall_streak_days)->toBe(2);

    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-agreements/{$agreement->id}/remittances/{$remittance->id}/withdraw-excuse", ['reason' => 'Again'])
        ->assertUnprocessable();
});

it('filters fleet cars by class and status, and tracks maintenance', function () {
    [, $token] = adminToken();

    $class = VehicleClass::factory()->create();
    $free = Vehicle::factory()->fleet()->create(['vehicle_class_id' => $class->id, 'driver_id' => null]);
    $servicing = Vehicle::factory()->fleet()->create(['vehicle_class_id' => $class->id, 'driver_id' => null]);
    Vehicle::factory()->fleet()->create(['vehicle_class_id' => $class->id, 'driver_id' => Driver::factory()]);
    Vehicle::factory()->fleet()->create();

    $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-vehicles/{$servicing->id}/maintenance", ['in_maintenance' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-vehicles/{$servicing->id}/maintenance", ['in_maintenance' => true, 'reason' => 'Brake pads'])
        ->assertOk()
        ->assertJsonPath('vehicle.fleet_status', 'maintenance')
        ->assertJsonPath('vehicle.maintenance.reason', 'Brake pads');

    $this->withToken($token)
        ->getJson("/api/v1/admin/fleet-vehicles?vehicle_class_id={$class->id}&status=available")
        ->assertOk()
        ->assertJsonCount(1, 'vehicles')
        ->assertJsonPath('vehicles.0.id', $free->id);

    $this->withToken($token)
        ->getJson('/api/v1/admin/fleet-vehicles?status=maintenance')
        ->assertJsonCount(1, 'vehicles')
        ->assertJsonPath('vehicles.0.id', $servicing->id);

    $driver = Driver::factory()->approved()->create();
    $this->withToken($token)
        ->postJson("/api/v1/admin/fleet-vehicles/{$servicing->id}/assign", [
            'driver_id' => $driver->id,
            'daily_remittance_target' => 40000,
            'total_vehicle_cost' => 5000000,
            'agreement_start_date' => now()->toDateString(),
        ])
        ->assertUnprocessable();

    $this->withToken($token)
        ->putJson("/api/v1/admin/fleet-vehicles/{$servicing->id}/maintenance", ['in_maintenance' => false])
        ->assertOk()
        ->assertJsonPath('vehicle.fleet_status', 'available');
});

it('searches drivers waiting for a car', function () {
    [, $token] = adminToken();

    $waiting = Driver::factory()->approved()->create(['vehicle_ownership_type' => 'fleet_vehicle']);
    $waiting->user->update(['first_name' => 'Chidi', 'last_name' => 'Okafor']);
    Driver::factory()->approved()->create(['vehicle_ownership_type' => 'fleet_vehicle']);

    $this->withToken($token)
        ->getJson('/api/v1/admin/fleet-vehicles/drivers-awaiting?search=chidi')
        ->assertOk()
        ->assertJsonCount(1, 'drivers')
        ->assertJsonPath('drivers.0.id', $waiting->id);
});

it('lets finance read vehicle classes and drivers but not change classes', function () {
    $finance = User::factory()->finance()->create();
    $token = $finance->createToken('admin-auth', ['admin'])->plainTextToken;
    $class = VehicleClass::factory()->create();

    $this->withToken($token)->getJson('/api/v1/admin/vehicle-classes')->assertOk();
    $this->withToken($token)->getJson('/api/v1/admin/drivers')->assertOk();
    $this->withToken($token)->putJson("/api/v1/admin/vehicle-classes/{$class->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/admin/drivers/pending')->assertForbidden();
});
