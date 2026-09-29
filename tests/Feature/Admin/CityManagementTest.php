<?php

use App\Models\City;
use App\Models\User;
use App\Models\VehicleClass;

it('lists cities for admin', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    City::factory()->count(3)->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/cities');

    $response->assertOk()
        ->assertJsonCount(3, 'cities')
        ->assertJsonStructure(['cities', 'meta']);
});

it('filters cities by is_active', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    City::factory()->count(2)->create();
    City::factory()->inactive()->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/cities?is_active=1');

    $response->assertOk()
        ->assertJsonCount(2, 'cities');
});

it('creates a city', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/cities', [
            'name' => 'Lagos',
            'timezone' => 'Africa/Lagos',
            'currency_code' => 'NGN',
            'boundary' => [
                'type' => 'Point',
                'coordinates' => [3.3792, 6.5244],
                'radius_km' => 30,
            ],
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'City created successfully.'])
        ->assertJsonPath('city.name', 'Lagos')
        ->assertJsonPath('city.slug', 'lagos');

    $this->assertDatabaseHas('cities', ['name' => 'Lagos', 'slug' => 'lagos']);
    $this->assertDatabaseHas('audit_logs', ['event' => 'city_created']);
});

it('validates required fields when creating a city', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/cities', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'timezone', 'currency_code']);
});

it('rejects duplicate city names', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    City::factory()->create(['name' => 'Lagos']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/cities', [
            'name' => 'Lagos',
            'timezone' => 'Africa/Lagos',
            'currency_code' => 'NGN',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('shows a city with vehicle classes', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $vehicleClass = VehicleClass::factory()->create();
    $city->vehicleClasses()->attach($vehicleClass->id, ['is_active' => true]);

    $response = $this->withToken($token)
        ->getJson("/api/v1/admin/cities/{$city->id}");

    $response->assertOk()
        ->assertJsonPath('city.id', $city->id)
        ->assertJsonCount(1, 'city.vehicle_classes');
});

it('updates a city', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create(['name' => 'Lagos']);

    $response = $this->withToken($token)
        ->putJson("/api/v1/admin/cities/{$city->id}", [
            'name' => 'Abuja',
            'timezone' => 'Africa/Lagos',
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'City updated successfully.'])
        ->assertJsonPath('city.name', 'Abuja')
        ->assertJsonPath('city.slug', 'abuja');

    $this->assertDatabaseHas('audit_logs', ['event' => 'city_updated']);
});

it('toggles city status', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create(['is_active' => true]);

    $response = $this->withToken($token)
        ->patchJson("/api/v1/admin/cities/{$city->id}/status");

    $response->assertOk()
        ->assertJson(['message' => 'City deactivated successfully.']);

    expect($city->fresh()->is_active)->toBeFalse();

    $this->withToken($token)
        ->patchJson("/api/v1/admin/cities/{$city->id}/status")
        ->assertOk()
        ->assertJson(['message' => 'City activated successfully.']);

    expect($city->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['event' => 'city_deactivated']);
    $this->assertDatabaseHas('audit_logs', ['event' => 'city_activated']);
});

it('updates city vehicle classes', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();
    $vc1 = VehicleClass::factory()->create();
    $vc2 = VehicleClass::factory()->create();

    $response = $this->withToken($token)
        ->putJson("/api/v1/admin/cities/{$city->id}/vehicle-classes", [
            'vehicle_classes' => [
                ['vehicle_class_id' => $vc1->id, 'is_active' => true],
                ['vehicle_class_id' => $vc2->id, 'is_active' => false],
            ],
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'City vehicle classes updated successfully.'])
        ->assertJsonCount(2, 'city.vehicle_classes');

    $this->assertDatabaseHas('city_vehicle_classes', [
        'city_id' => $city->id,
        'vehicle_class_id' => $vc1->id,
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('city_vehicle_classes', [
        'city_id' => $city->id,
        'vehicle_class_id' => $vc2->id,
        'is_active' => false,
    ]);
});

it('validates vehicle class ids when updating city vehicle classes', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = City::factory()->create();

    $response = $this->withToken($token)
        ->putJson("/api/v1/admin/cities/{$city->id}/vehicle-classes", [
            'vehicle_classes' => [
                ['vehicle_class_id' => 9999, 'is_active' => true],
            ],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicle_classes.0.vehicle_class_id']);
});

it('prevents non-admin from managing cities', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/cities');

    $response->assertForbidden();
});
