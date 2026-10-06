<?php

use App\Models\User;
use App\Models\VehicleClass;

it('lists vehicle classes for admin', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    VehicleClass::factory()->count(3)->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/vehicle-classes');

    $response->assertOk()
        ->assertJsonCount(3, 'vehicle_classes')
        ->assertJsonStructure(['vehicle_classes', 'meta']);
});

it('filters vehicle classes by is_active', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    VehicleClass::factory()->count(2)->create();
    VehicleClass::factory()->inactive()->create();

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/vehicle-classes?is_active=0');

    $response->assertOk()
        ->assertJsonCount(1, 'vehicle_classes');
});

it('creates a vehicle class', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/vehicle-classes', [
            'name' => 'economy',
            'display_name' => 'Economy',
            'capacity' => 4,
            'description' => 'Affordable rides for everyday trips.',
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'Vehicle class created successfully.'])
        ->assertJsonPath('vehicle_class.name', 'economy')
        ->assertJsonPath('vehicle_class.capacity', 4);

    $this->assertDatabaseHas('vehicle_classes', ['name' => 'economy']);
    $this->assertDatabaseHas('audit_logs', ['event' => 'vehicle_class_created']);
});

it('creates a vehicle class with icon, status and city assignments', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $city = \App\Models\City::factory()->create();

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/vehicle-classes', [
            'name' => 'comfort',
            'display_name' => 'Comfort',
            'capacity' => 4,
            'icon' => 'comfort',
            'is_active' => false,
            'city_ids' => [$city->id],
        ]);

    $response->assertCreated()
        ->assertJsonPath('vehicle_class.icon', 'comfort')
        ->assertJsonPath('vehicle_class.is_active', false);

    $this->assertDatabaseHas('vehicle_classes', ['name' => 'comfort', 'icon' => 'comfort', 'is_active' => false]);
    $this->assertDatabaseHas('city_vehicle_classes', ['city_id' => $city->id]);
});

it('rejects invalid icon slug', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/vehicle-classes', [
            'name' => 'sedan',
            'display_name' => 'Sedan',
            'capacity' => 4,
            'icon' => 'sedan',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['icon']);
});

it('validates required fields when creating a vehicle class', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/vehicle-classes', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'display_name', 'capacity']);
});

it('rejects duplicate vehicle class names', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    VehicleClass::factory()->create(['name' => 'economy']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/admin/vehicle-classes', [
            'name' => 'economy',
            'display_name' => 'Economy',
            'capacity' => 4,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('shows a vehicle class', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $vc = VehicleClass::factory()->create();

    $response = $this->withToken($token)
        ->getJson("/api/v1/admin/vehicle-classes/{$vc->id}");

    $response->assertOk()
        ->assertJsonPath('vehicle_class.id', $vc->id);
});

it('updates a vehicle class', function () {
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('admin-auth', ['admin'])->plainTextToken;

    $vc = VehicleClass::factory()->create(['name' => 'economy', 'capacity' => 4]);

    $response = $this->withToken($token)
        ->putJson("/api/v1/admin/vehicle-classes/{$vc->id}", [
            'display_name' => 'Economy Plus',
            'capacity' => 5,
        ]);

    $response->assertOk()
        ->assertJson(['message' => 'Vehicle class updated successfully.'])
        ->assertJsonPath('vehicle_class.display_name', 'Economy Plus')
        ->assertJsonPath('vehicle_class.capacity', 5);

    $this->assertDatabaseHas('audit_logs', ['event' => 'vehicle_class_updated']);
});

it('prevents non-admin from managing vehicle classes', function () {
    $passenger = User::factory()->passenger()->create();
    $token = $passenger->createToken('test', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/vehicle-classes');

    $response->assertForbidden();
});
