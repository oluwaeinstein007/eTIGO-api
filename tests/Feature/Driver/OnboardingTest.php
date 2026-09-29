<?php

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('returns driver onboarding status', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/driver/onboarding/status');

    $response->assertOk()
        ->assertJsonStructure([
            'driver',
            'onboarding_complete',
            'missing_documents',
            'has_vehicle',
            'has_licence_number',
        ]);
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
    Storage::fake('local');

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
    Storage::fake('local');

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

it('lists driver documents', function () {
    Storage::fake('local');

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
        ->assertJsonCount(1, 'documents');
});

it('allows driver to register a vehicle', function () {
    $driver = Driver::factory()->create();
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/vehicle', [
            'make' => 'Toyota',
            'model' => 'Corolla',
            'colour' => 'White',
            'plate_number' => 'ABC-1234',
            'year' => 2022,
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'Vehicle registered successfully.']);

    $this->assertDatabaseHas('vehicles', [
        'driver_id' => $driver->id,
        'make' => 'Toyota',
        'plate_number' => 'ABC-1234',
    ]);
});

it('prevents duplicate vehicle registration', function () {
    $driver = Driver::factory()->create();
    Vehicle::factory()->create(['driver_id' => $driver->id]);
    $token = $driver->user->createToken('test', ['driver'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/driver/vehicle', [
            'make' => 'Honda',
            'model' => 'Civic',
            'colour' => 'Black',
            'plate_number' => 'XYZ-9999',
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
