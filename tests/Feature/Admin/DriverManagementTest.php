<?php

use App\Enums\DocumentStatus;
use App\Enums\DriverStatus;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\VehicleOwnershipType;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;

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

    $driver = Driver::factory()->create([
        'kyc_status' => KycStatus::Verified,
        'kyc_verified_at' => now(),
    ]);

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

it('prevents non-admin from completing driver onboarding', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;
    $driver = Driver::factory()->create();

    $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding")
        ->assertForbidden();
});

it('rejects completing onboarding when driver is missing required vehicle ownership type', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->create([
        'city_id' => $city->id,
        'licence_number' => 'DL-12345',
        'vehicle_ownership_type' => null,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Cannot complete onboarding. Driver has not provided all required data.',
        ])
        ->assertJsonFragment(['Vehicle ownership type must be selected.']);
});

it('rejects completing onboarding when driver is missing required licence number or city', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
        'city_id' => null,
        'licence_number' => null,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Cannot complete onboarding. Driver has not provided all required data.',
        ])
        ->assertJsonFragment(['Licence number must be provided.'])
        ->assertJsonFragment(['City must be selected.']);
});

it('rejects completing onboarding when own-vehicle driver has not registered a vehicle', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::OwnVehicle,
        'city_id' => $city->id,
        'licence_number' => 'DL-99999',
    ]);

    // Add documents
    foreach (['driving_licence', 'government_id', 'vehicle_registration', 'insurance_certificate'] as $docType) {
        $driver->documents()->create([
            'type' => $docType,
            'file_path' => "docs/{$docType}.pdf",
            'original_filename' => "{$docType}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => DocumentStatus::Pending,
        ]);
    }

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Cannot complete onboarding. Driver has not provided all required data.',
        ])
        ->assertJsonFragment(['Vehicle details must be provided for own-vehicle drivers.']);
});

it('rejects completing onboarding when required documents are missing', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
        'city_id' => $city->id,
        'licence_number' => 'DL-77777',
    ]);

    // Only upload driving_licence, missing government_id
    $driver->documents()->create([
        'type' => 'driving_licence',
        'file_path' => 'docs/dl.pdf',
        'original_filename' => 'dl.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'status' => DocumentStatus::Pending,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Cannot complete onboarding. Driver has not provided all required data.',
        ])
        ->assertJsonFragment(['Missing required documents: government_id.']);
});

it('rejects completing onboarding for suspended driver', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->suspended()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
        'city_id' => $city->id,
        'licence_number' => 'DL-11111',
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertStatus(422)
        ->assertJson(['message' => 'Driver is currently suspended. Please reactivate the driver instead.']);
});

it('successfully completes onboarding and approves driver without kyc for own-vehicle driver', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::OwnVehicle,
        'city_id' => $city->id,
        'licence_number' => 'DL-55555',
        'status' => DriverStatus::PendingReview,
        'kyc_status' => KycStatus::NotStarted,
    ]);

    $vehicleClass = VehicleClass::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_class_id' => $vehicleClass->id,
        'plate_number' => 'LAG-123-XY',
        'is_fleet' => false,
    ]);

    foreach (['driving_licence', 'government_id', 'vehicle_registration', 'insurance_certificate'] as $docType) {
        $driver->documents()->create([
            'type' => $docType,
            'file_path' => "docs/{$docType}.pdf",
            'original_filename' => "{$docType}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => DocumentStatus::Pending,
        ]);
    }

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding", [
            'notes' => 'Bypassed external KYC after physical inspection',
            'nin' => '12345678901',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Driver onboarding and KYC marked as completed successfully.']);

    $driver->refresh();
    expect($driver->status)->toBe(DriverStatus::Approved);
    expect($driver->kyc_status)->toBe(KycStatus::Verified);
    expect($driver->approved_at)->not->toBeNull();
    expect($driver->kyc_verified_at)->not->toBeNull();

    // Verify documents are approved
    expect($driver->documents()->where('status', DocumentStatus::Approved)->count())->toBe(4);
    expect($driver->documents()->where('reviewed_by', $admin->id)->count())->toBe(4);

    // Verify vehicle is associated
    expect($driver->vehicle)->not->toBeNull();
    expect($driver->canGoOnline())->toBeTrue();

    // Verify KYC verifications were created
    $verifications = $driver->kycVerifications;
    expect($verifications->pluck('type.value')->toArray())->toContain('nin', 'drivers_license', 'vehicle_plate');
    expect($verifications->every(fn ($v) => $v->status === KycVerificationStatus::Verified))->toBeTrue();

    $ninVerification = $verifications->firstWhere('type.value', 'nin');
    expect($ninVerification->id_number)->toBe('12345678901');
    expect($ninVerification->provider_reference)->toBe('admin_bypass');

    $dlVerification = $verifications->firstWhere('type.value', 'drivers_license');
    expect($dlVerification->id_number)->toBe('DL-55555');

    $plateVerification = $verifications->firstWhere('type.value', 'vehicle_plate');
    expect($plateVerification->id_number)->toBe('LAG-123-XY');

    // Verify AuditLog
    expect(AuditLog::where('auditable_id', $driver->id)
        ->where('event', 'driver_onboarding_and_kyc_completed_by_admin')
        ->exists())->toBeTrue();
});

it('successfully completes onboarding and approves fleet-vehicle driver awaiting vehicle assignment', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
        'city_id' => $city->id,
        'licence_number' => 'DL-FLEET-01',
        'status' => DriverStatus::PendingReview,
        'kyc_status' => KycStatus::NotStarted,
    ]);

    foreach (['driving_licence', 'government_id'] as $docType) {
        $driver->documents()->create([
            'type' => $docType,
            'file_path' => "docs/{$docType}.pdf",
            'original_filename' => "{$docType}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => DocumentStatus::Pending,
        ]);
    }

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertOk()
        ->assertJson(['message' => 'Driver onboarding and KYC marked as completed successfully.']);

    $driver->refresh();
    expect($driver->status)->toBe(DriverStatus::Approved);
    expect($driver->kyc_status)->toBe(KycStatus::Verified);
    expect($driver->isAwaitingVehicleAssignment())->toBeTrue();

    // Fleet driver requires nin and drivers_license, not vehicle_plate
    $verifications = $driver->kycVerifications;
    expect($verifications->pluck('type.value')->toArray())->toContain('nin', 'drivers_license');
    expect($verifications->pluck('type.value')->toArray())->not->toContain('vehicle_plate');
});

it('returns 200 idempotently if driver onboarding and kyc are already completed', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $driver = Driver::factory()->approved()->create();

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertOk()
        ->assertJson(['message' => 'Driver onboarding and KYC are already completed and approved.']);
});

it('successfully completes onboarding for legacy driver with null vehicle_ownership_type and existing vehicle', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => null,
        'city_id' => $city->id,
        'licence_number' => 'DL-LEGACY-01',
        'status' => DriverStatus::PendingReview,
        'kyc_status' => KycStatus::NotStarted,
    ]);

    $vehicleClass = VehicleClass::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_class_id' => $vehicleClass->id,
        'plate_number' => 'LEG-123-XY',
        'is_fleet' => false,
    ]);

    foreach (['driving_licence', 'government_id', 'vehicle_registration', 'insurance_certificate'] as $docType) {
        $driver->documents()->create([
            'type' => $docType,
            'file_path' => "docs/{$docType}.pdf",
            'original_filename' => "{$docType}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => DocumentStatus::Approved,
        ]);
    }

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding");

    $response->assertOk()
        ->assertJson(['message' => 'Driver onboarding and KYC marked as completed successfully.']);

    $driver->refresh();
    expect($driver->status)->toBe(DriverStatus::Approved);
    expect($driver->kyc_status)->toBe(KycStatus::Verified);
    expect($driver->vehicle_ownership_type)->toBe(VehicleOwnershipType::OwnVehicle);
});

it('allows setting vehicle_ownership_type via complete-onboarding request payload', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => null,
        'city_id' => $city->id,
        'licence_number' => 'DL-FLEET-PARAM',
        'status' => DriverStatus::PendingReview,
        'kyc_status' => KycStatus::NotStarted,
    ]);

    foreach (['driving_licence', 'government_id'] as $docType) {
        $driver->documents()->create([
            'type' => $docType,
            'file_path' => "docs/{$docType}.pdf",
            'original_filename' => "{$docType}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => DocumentStatus::Pending,
        ]);
    }

    $response = $this->withToken($token)
        ->postJson("/api/v1/admin/drivers/{$driver->id}/complete-onboarding", [
            'vehicle_ownership_type' => 'fleet_vehicle',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Driver onboarding and KYC marked as completed successfully.']);

    $driver->refresh();
    expect($driver->status)->toBe(DriverStatus::Approved);
    expect($driver->kyc_status)->toBe(KycStatus::Verified);
    expect($driver->vehicle_ownership_type)->toBe(VehicleOwnershipType::FleetVehicle);
});
