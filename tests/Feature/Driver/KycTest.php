<?php

use App\Contracts\KycGateway;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Jobs\VerifyVehiclePlateJob;
use App\Models\Driver;
use App\Models\KycVerification;
use App\Models\User;
use App\Models\VehicleClass;
use App\Services\FakeKycGateway;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->app->bind(KycGateway::class, FakeKycGateway::class);
});

it('returns kyc status for a driver', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/kyc/status');

    $response->assertOk()
        ->assertJsonStructure([
            'kyc_status',
            'kyc_verified_at',
            'verifications' => [
                'nin',
                'drivers_license',
                'liveness',
            ],
        ]);
});

it('can verify NIN successfully', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'NIN verified successfully.'])
        ->assertJsonStructure([
            'verification' => ['id', 'type', 'status', 'verified_at'],
        ]);

    $this->assertDatabaseHas('kyc_verifications', [
        'driver_id' => $driver->id,
        'type' => 'nin',
        'status' => 'verified',
    ]);
});

it('reports NIN verification failure for invalid NIN', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '00012345678',
        ]);

    $response->assertUnprocessable();

    $this->assertDatabaseHas('kyc_verifications', [
        'driver_id' => $driver->id,
        'type' => 'nin',
        'status' => 'failed',
    ]);
});

it('validates NIN must be 11 digits', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('nin_number');
});

it('prevents duplicate NIN verification when already verified', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    KycVerification::create([
        'driver_id' => $driver->id,
        'type' => KycVerificationType::Nin,
        'id_number' => '12345678901',
        'status' => KycVerificationStatus::Verified,
        'verified_at' => now(),
    ]);

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ]);

    $response->assertStatus(409);
});

it('can verify drivers license successfully', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', [
            'license_number' => 'ABC123DEF',
        ]);

    $response->assertOk()
        ->assertJson(['message' => "Driver's license verified successfully."])
        ->assertJsonStructure([
            'verification' => ['id', 'type', 'status', 'verified_at'],
        ]);

    $this->assertDatabaseHas('kyc_verifications', [
        'driver_id' => $driver->id,
        'type' => 'drivers_license',
        'status' => 'verified',
    ]);
});

it('reports license verification failure', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', [
            'license_number' => '000FAIL',
        ]);

    $response->assertUnprocessable();

    $this->assertDatabaseHas('kyc_verifications', [
        'driver_id' => $driver->id,
        'type' => 'drivers_license',
        'status' => 'failed',
    ]);
});

it('can create a liveness session', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/liveness-session');

    $response->assertCreated()
        ->assertJsonStructure([
            'message',
            'session_id',
            'sdk_token',
            'expires_at',
            'verification',
        ]);
});

it('updates driver kyc_status to verified when all required checks pass', function () {
    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'LAG-123AB',
        'year' => 2022,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ]);

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', [
            'license_number' => 'ABC123DEF',
        ]);

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-vehicle', [
            'plate_number' => 'LAG-123AB',
        ]);

    expect($driver->fresh()->kyc_status)->toBe(KycStatus::Verified);
    expect($driver->fresh()->kyc_verified_at)->not->toBeNull();
});

it('sets driver kyc_status to failed when a check fails', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '00012345678',
        ]);

    expect($driver->fresh()->kyc_status)->toBe(KycStatus::Failed);
});

it('lists all kyc verifications for a driver', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    KycVerification::create([
        'driver_id' => $driver->id,
        'type' => KycVerificationType::Nin,
        'id_number' => '12345678901',
        'status' => KycVerificationStatus::Verified,
        'verified_at' => now(),
    ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/kyc/verifications');

    $response->assertOk()
        ->assertJsonCount(1, 'verifications');
});

it('returns 404 for non-driver user accessing kyc', function () {
    $user = User::factory()->passenger()->create();
    $token = $user->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/kyc/status');

    $response->assertForbidden();
});

it('allows failed verification to be retried', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    KycVerification::create([
        'driver_id' => $driver->id,
        'type' => KycVerificationType::Nin,
        'id_number' => '00012345678',
        'status' => KycVerificationStatus::Failed,
        'failure_reason' => 'Name mismatch',
    ]);

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ]);

    $response->assertOk();

    expect($driver->kycVerifications()->where('type', 'nin')->count())->toBe(2);
});

it('handles webhook for liveness completion', function () {
    $driver = Driver::factory()->create();

    $verification = KycVerification::create([
        'driver_id' => $driver->id,
        'type' => KycVerificationType::Liveness,
        'provider_reference' => 'fake_sess_test123',
        'status' => KycVerificationStatus::Processing,
    ]);

    $secret = 'test-webhook-secret';
    config(['services.qoreid.webhook_secret' => $secret]);

    $payload = json_encode([
        'event' => 'verification_completed',
        'sessionId' => 'fake_sess_test123',
    ]);
    $signature = hash_hmac('sha256', $payload, $secret);

    $response = $this->postJson('/api/v1/webhooks/qoreid', json_decode($payload, true), [
        'X-QoreID-Signature' => $signature,
    ]);

    $response->assertOk();

    expect($verification->fresh()->status)->toBe(KycVerificationStatus::Verified);
});

// --- Vehicle Plate Verification ---

it('can verify vehicle plate successfully', function () {
    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'LAG-123AB',
        'year' => 2022,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-vehicle', [
            'plate_number' => 'LAG-123AB',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Vehicle plate verified successfully.']);

    $this->assertDatabaseHas('kyc_verifications', [
        'driver_id' => $driver->id,
        'type' => 'vehicle_plate',
        'status' => 'verified',
    ]);
});

it('reports vehicle plate verification failure', function () {
    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => '000-FAIL',
        'year' => 2022,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-vehicle', [
            'plate_number' => '000-FAIL',
        ]);

    $response->assertUnprocessable();

    $this->assertDatabaseHas('kyc_verifications', [
        'driver_id' => $driver->id,
        'type' => 'vehicle_plate',
        'status' => 'failed',
    ]);
});

it('requires vehicle before plate verification', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-vehicle', [
            'plate_number' => 'LAG-123AB',
        ]);

    $response->assertUnprocessable()
        ->assertJson(['message' => 'Register a vehicle before verifying its plate.']);
});

it('auto-triggers plate verification on vehicle registration', function () {
    Queue::fake();

    $driver = Driver::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/vehicle', [
            'make' => 'Toyota',
            'model' => 'Corolla',
            'colour' => 'White',
            'plate_number' => 'ABJ-456XY',
            'year' => 2023,
            'vehicle_class_id' => $vehicleClass->id,
        ]);

    Queue::assertPushed(VerifyVehiclePlateJob::class);
});

it('re-verifies plate when plate number changes on vehicle update', function () {
    Queue::fake();

    $driver = Driver::factory()->create();
    $vehicle = $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'LAG-OLD01',
        'year' => 2022,
    ]);

    KycVerification::create([
        'driver_id' => $driver->id,
        'type' => KycVerificationType::VehiclePlate,
        'id_number' => 'LAG-OLD01',
        'status' => KycVerificationStatus::Verified,
        'verified_at' => now(),
    ]);

    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/driver/vehicle', [
            'plate_number' => 'LAG-NEW02',
        ]);

    Queue::assertPushed(VerifyVehiclePlateJob::class);

    $this->assertDatabaseHas('kyc_verifications', [
        'driver_id' => $driver->id,
        'type' => 'vehicle_plate',
        'status' => 'expired',
    ]);
});

// --- Fleet Vehicle ---

it('marks fleet vehicle kyc as verified without plate check', function () {
    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'FLEET-001',
        'year' => 2022,
        'is_fleet' => true,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ]);

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', [
            'license_number' => 'ABC123DEF',
        ]);

    expect($driver->fresh()->kyc_status)->toBe(KycStatus::Verified);
    expect($driver->fresh()->kyc_verified_at)->not->toBeNull();
});

it('skips plate verification when vehicle is fleet', function () {
    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'FLEET-002',
        'year' => 2023,
        'is_fleet' => true,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', ['nin_number' => '12345678901'])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', ['license_number' => 'ABC123DEF'])
        ->assertOk();

    expect($driver->fresh()->kyc_status)->toBe(KycStatus::Verified);
});

it('admin can toggle fleet status on a vehicle', function () {
    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'LAG-TOGGLE',
        'year' => 2022,
    ]);

    $admin = User::factory()->admin()->create();
    $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($adminToken)
        ->patchJson("/api/v1/admin/drivers/{$driver->id}/vehicle/fleet");

    $response->assertOk()
        ->assertJson(['message' => 'Vehicle marked as fleet. Plate verification skipped.']);

    expect($driver->vehicle->fresh()->is_fleet)->toBeTrue();

    $response = $this->withToken($adminToken)
        ->patchJson("/api/v1/admin/drivers/{$driver->id}/vehicle/fleet");

    $response->assertOk()
        ->assertJson(['message' => 'Vehicle unmarked as fleet. Plate verification now required.']);

    expect($driver->vehicle->fresh()->is_fleet)->toBeFalse();
});

it('fleet status summary shows vehicle_plate as not required', function () {
    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'FLEET-003',
        'year' => 2022,
        'is_fleet' => true,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/kyc/status');

    $response->assertOk();

    $verifications = $response->json('verifications');
    expect($verifications['vehicle_plate']['required'])->toBeFalse();
    expect($verifications['nin']['required'])->toBeTrue();
    expect($verifications['drivers_license']['required'])->toBeTrue();
});

it('does not re-verify plate when other vehicle fields change', function () {
    Queue::fake();

    $driver = Driver::factory()->create();
    $driver->vehicle()->create([
        'make' => 'Toyota',
        'model' => 'Corolla',
        'colour' => 'White',
        'plate_number' => 'LAG-SAME1',
        'year' => 2022,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->putJson('/api/v1/driver/vehicle', [
            'colour' => 'Black',
        ]);

    Queue::assertNotPushed(VerifyVehiclePlateJob::class);
});
