<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DriverStatus;
use App\Enums\VehicleOwnershipType;
use App\Models\City;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleClass;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('returns driver onboarding status', function () {
    $driver = Driver::factory()->create(['vehicle_ownership_type' => null]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/onboarding/status');

    $response->assertOk()
        ->assertJsonStructure([
            'driver',
            'onboarding_complete',
            'can_submit',
            'required_documents',
            'missing_documents',
            'has_vehicle',
            'has_licence_number',
            'has_city',
        ])
        ->assertJsonPath('required_documents', ['driving_licence', 'government_id', 'vehicle_registration', 'insurance_certificate']);
});

it('asks fleet drivers for only their licence and government ID', function () {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Onboarding,
        'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
        'city_id' => City::factory(),
    ]);
    foreach ([DocumentType::DrivingLicence, DocumentType::GovernmentId] as $type) {
        $driver->documents()->create([
            'type' => $type,
            'file_path' => "driver-documents/{$driver->id}/{$type->value}.jpg",
            'original_filename' => "{$type->value}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);
    }
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/driver/onboarding/status')
        ->assertOk()
        ->assertJsonPath('required_documents', ['driving_licence', 'government_id'])
        ->assertJsonPath('missing_documents', [])
        ->assertJsonPath('onboarding_complete', true)
        ->assertJsonPath('can_submit', true);

    $this->withToken($token)
        ->postJson('/api/v1/driver/onboarding/submit')
        ->assertOk();
});

it('asks own-vehicle drivers for their vehicle papers too', function () {
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::OwnVehicle,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/driver/onboarding/status')
        ->assertOk()
        ->assertJsonPath('required_documents', ['driving_licence', 'government_id', 'vehicle_registration', 'insurance_certificate']);
});

it('still lists missing vehicle papers for an own-vehicle driver with identity documents uploaded', function () {
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::OwnVehicle,
    ]);
    foreach ([DocumentType::DrivingLicence, DocumentType::GovernmentId] as $type) {
        $driver->documents()->create([
            'type' => $type,
            'file_path' => "driver-documents/{$driver->id}/{$type->value}.jpg",
            'original_filename' => "{$type->value}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);
    }
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/driver/onboarding/status')
        ->assertOk()
        ->assertJsonPath('required_documents', ['driving_licence', 'government_id', 'vehicle_registration', 'insurance_certificate'])
        ->assertJsonPath('missing_documents', ['vehicle_registration', 'insurance_certificate']);
});

it('allows driver to update their profile', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->putJson('/api/v1/driver/profile', [
            'licence_number' => 'AB123CDE',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Driver profile updated successfully.']);

    expect($driver->fresh()->licence_number)->toBe('AB123CDE');
});

it('allows driver to upload a KYC document', function () {
    Storage::fake(config('filesystems.uploads'));

    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/documents', [
            'type' => 'driving_licence',
            'document' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf'),
        ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'document' => ['id', 'type', 'status']]);

    $this->assertDatabaseHas('driver_documents', [
        'driver_id' => $driver->id,
        'type' => 'driving_licence',
        'status' => 'pending',
    ]);
});

it('replaces existing document of same type on re-upload', function () {
    Storage::fake(config('filesystems.uploads'));

    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/documents', [
            'type' => 'driving_licence',
            'document' => UploadedFile::fake()->create('licence1.pdf', 100, 'application/pdf'),
        ]);

    $this->withToken($token)
        ->postJson('/api/v1/driver/documents', [
            'type' => 'driving_licence',
            'document' => UploadedFile::fake()->create('licence2.pdf', 100, 'application/pdf'),
        ]);

    expect($driver->documents()->where('type', 'driving_licence')->count())->toBe(1);
});

it('lists driver documents as a complete required checklist', function () {
    Storage::fake(config('filesystems.uploads'));

    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/driver/documents', [
            'type' => 'driving_licence',
            'document' => UploadedFile::fake()->create('licence.pdf', 100, 'application/pdf'),
        ]);

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/documents');

    $response->assertOk()
        ->assertJsonCount(4, 'documents')
        ->assertJsonPath('total_required', 4)
        ->assertJsonPath('total_uploaded', 1)
        ->assertJsonPath('can_submit', false)
        ->assertJsonPath('documents.0.type', 'driving_licence')
        ->assertJsonPath('documents.0.name', 'Driving licence')
        ->assertJsonPath('documents.0.status', 'under_review')
        ->assertJsonPath('documents.0.original_filename', 'licence.pdf')
        ->assertJsonPath('documents.1.type', 'government_id')
        ->assertJsonPath('documents.1.status', 'not_submitted')
        ->assertJsonPath('documents.1.id', null);
});

it('lists only 2 required documents for a fleet vehicle driver', function () {
    $driver = Driver::factory()->create([
        'vehicle_ownership_type' => VehicleOwnershipType::FleetVehicle,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/documents');

    $response->assertOk()
        ->assertJsonCount(2, 'documents')
        ->assertJsonPath('total_required', 2)
        ->assertJsonPath('documents.0.type', 'driving_licence')
        ->assertJsonPath('documents.1.type', 'government_id');
});

it('shows rejected status and rejection reason in the checklist', function () {
    $driver = Driver::factory()->create();
    $driver->documents()->create([
        'type' => DocumentType::DrivingLicence,
        'file_path' => 'driver-documents/test.jpg',
        'original_filename' => 'license.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => DocumentStatus::Rejected,
        'rejection_reason' => 'Blurry photo, please retake',
        'uploaded_by' => $driver->user_id,
    ]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/documents');

    $response->assertOk()
        ->assertJsonCount(4, 'documents')
        ->assertJsonPath('documents.0.type', 'driving_licence')
        ->assertJsonPath('documents.0.status', 'rejected')
        ->assertJsonPath('documents.0.rejection_reason', 'Blurry photo, please retake')
        ->assertJsonPath('total_uploaded', 0)
        ->assertJsonPath('can_submit', false);
});

it('allows driver to register a vehicle', function () {
    $driver = Driver::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/vehicle', [
            'make' => 'Toyota',
            'model' => 'Corolla',
            'colour' => 'White',
            'plate_number' => 'ABC-1234',
            'year' => 2022,
            'fuel_type' => 'electric',
            'vehicle_class_id' => $vehicleClass->id,
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'Vehicle registered successfully. Plate verification initiated.'])
        ->assertJsonPath('vehicle.fuel_type', 'electric')
        ->assertJsonPath('vehicle.can_charge', true)
        ->assertJsonPath('vehicle.is_fleet', false);

    $this->assertDatabaseHas('vehicles', [
        'driver_id' => $driver->id,
        'make' => 'Toyota',
        'plate_number' => 'ABC-1234',
        'fuel_type' => 'electric',
        'vehicle_class_id' => $vehicleClass->id,
    ]);
});

it('prevents duplicate vehicle registration', function () {
    $driver = Driver::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    Vehicle::factory()->create(['driver_id' => $driver->id]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/vehicle', [
            'make' => 'Honda',
            'model' => 'Civic',
            'colour' => 'Black',
            'plate_number' => 'XYZ-9999',
            'year' => 2023,
            'fuel_type' => 'petrol',
            'vehicle_class_id' => $vehicleClass->id,
        ]);

    $response->assertStatus(409);
});

it('prevents passenger from accessing driver routes', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/onboarding/status');

    $response->assertForbidden();
});

it('replaces a rejected document when re-uploading', function () {
    Storage::fake(config('filesystems.uploads'));

    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $oldDoc = $driver->documents()->create([
        'uploaded_by' => $driver->user_id,
        'type' => DocumentType::DrivingLicence,
        'file_path' => 'driver-documents/old_licence.jpg',
        'original_filename' => 'old_licence.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'status' => DocumentStatus::Rejected,
        'rejection_reason' => 'Blurry image',
    ]);
    Storage::disk(config('filesystems.uploads'))->put('driver-documents/old_licence.jpg', 'old');

    $file = UploadedFile::fake()->image('new_licence.jpg');

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/documents', [
            'type' => 'driving_licence',
            'document' => $file,
        ]);

    $response->assertCreated();

    $this->assertDatabaseMissing('driver_documents', [
        'id' => $oldDoc->id,
    ]);
    $this->assertDatabaseHas('driver_documents', [
        'driver_id' => $driver->id,
        'type' => 'driving_licence',
        'original_filename' => 'new_licence.jpg',
        'status' => 'pending',
    ]);
});
