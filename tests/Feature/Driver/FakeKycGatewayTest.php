<?php

use App\Contracts\KycGateway;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\KycVerificationType;
use App\Enums\VehicleOwnershipType;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use App\Services\FakeKycGateway;

beforeEach(function () {
    config(['services.qoreid.skip_verification' => true]);
});

it('resolves FakeKycGateway when skip_verification config is true', function () {
    $gateway = app(KycGateway::class);
    expect($gateway)->toBeInstanceOf(FakeKycGateway::class);
});

it('allows driver to verify NIN using FakeKycGateway when skip verification flag is true', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'NIN verified successfully.',
            'verification' => [
                'type' => 'nin',
                'status' => 'verified',
            ],
        ]);

    $verification = $driver->kycVerifications()->where('type', KycVerificationType::Nin)->first();
    expect($verification)->not->toBeNull();
    expect($verification->status)->toBe(KycVerificationStatus::Verified);
    expect($verification->id_number)->toBe('12345678901');
    expect($verification->provider_reference)->toStartWith('mock_nin_');
    expect($verification->match_data['simulated'])->toBeTrue();
    expect($verification->verified_at)->not->toBeNull();
});

it('still enforces NIN validation rules when skip verification flag is true', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '123',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('nin_number');
});

it('still prevents duplicate NIN verification when skip verification flag is true', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ])
        ->assertOk();

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ]);

    $response->assertStatus(409)
        ->assertJsonFragment(['message' => 'A National Identification Number verification is already completed or in progress.']);
});

it('allows driver to verify driver license using FakeKycGateway', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', [
            'license_number' => 'ABC12345DEF',
        ]);

    $response->assertOk()
        ->assertJson([
            'message' => "Driver's license verified successfully.",
            'verification' => [
                'type' => 'drivers_license',
                'status' => 'verified',
            ],
        ]);

    $verification = $driver->kycVerifications()->where('type', KycVerificationType::DriversLicense)->first();
    expect($verification)->not->toBeNull();
    expect($verification->status)->toBe(KycVerificationStatus::Verified);
    expect($verification->provider_reference)->toStartWith('mock_dl_');
    expect($verification->match_data['simulated'])->toBeTrue();
});

it('still enforces license validation rules when skip verification flag is true', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', [
            'license_number' => 'AB',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('license_number');
});

it('allows driver to verify vehicle plate using FakeKycGateway', function () {
    $driver = Driver::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    Vehicle::factory()->create([
        'driver_id' => $driver->id,
        'vehicle_class_id' => $vehicleClass->id,
        'plate_number' => 'LAG-777-ZZ',
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-vehicle', [
            'plate_number' => 'LAG-777-ZZ',
        ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'Vehicle plate verified successfully.',
            'verification' => [
                'type' => 'vehicle_plate',
                'status' => 'verified',
            ],
        ]);

    $verification = $driver->kycVerifications()->where('type', KycVerificationType::VehiclePlate)->first();
    expect($verification)->not->toBeNull();
    expect($verification->status)->toBe(KycVerificationStatus::Verified);
    expect($verification->provider_reference)->toStartWith('mock_plate_');
    expect($verification->match_data['simulated'])->toBeTrue();
});

it('still requires vehicle registration before vehicle plate verification', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-vehicle', [
            'plate_number' => 'LAG-777-ZZ',
        ]);

    $response->assertStatus(422)
        ->assertJson(['message' => 'Register a vehicle before verifying its plate.']);
});

it('allows driver to create liveness session using FakeKycGateway', function () {
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

it('transitions driver kyc_status to verified when all required verifications succeed in skip mode', function () {
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
        'kyc_status' => KycStatus::NotStarted,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    // Fleet vehicle requires NIN + Drivers License
    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-nin', [
            'nin_number' => '12345678901',
        ])
        ->assertOk();

    expect($driver->fresh()->kyc_status)->toBe(KycStatus::InProgress);

    $this->withToken($token)
        ->postJson('/api/v1/driver/kyc/verify-license', [
            'license_number' => 'ABC12345DEF',
        ])
        ->assertOk();

    $driver->refresh();
    expect($driver->kyc_status)->toBe(KycStatus::Verified);
    expect($driver->kyc_verified_at)->not->toBeNull();

    // Check status endpoint returns verified
    $statusResponse = $this->withToken($token)
        ->getJson('/api/v1/driver/kyc/status');

    $statusResponse->assertOk()
        ->assertJson([
            'kyc_status' => 'verified',
            'verifications' => [
                'nin' => [
                    'status' => 'verified',
                    'required' => true,
                ],
                'drivers_license' => [
                    'status' => 'verified',
                    'required' => true,
                ],
            ],
        ]);
});
