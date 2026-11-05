<?php

use App\Enums\AdminRole;
use App\Enums\FuelType;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\VehicleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest can list vehicle makes with active models', function () {
    $toyota = VehicleMake::create(['name' => 'Toyota', 'sort_order' => 1]);
    $byd = VehicleMake::create(['name' => 'BYD', 'sort_order' => 2]);

    VehicleModel::create([
        'vehicle_make_id' => $toyota->id,
        'name' => 'Corolla',
        'fuel_type' => FuelType::Petrol,
    ]);

    VehicleModel::create([
        'vehicle_make_id' => $byd->id,
        'name' => 'Dolphin',
        'fuel_type' => FuelType::Electric,
    ]);

    $response = $this->getJson('/api/v1/vehicle-makes');

    $response->assertOk()
        ->assertJsonStructure([
            'makes' => [
                '*' => [
                    'id',
                    'name',
                    'slug',
                    'is_active',
                    'models' => [
                        '*' => [
                            'id',
                            'vehicle_make_id',
                            'name',
                            'fuel_type',
                            'fuel_type_label',
                        ],
                    ],
                ],
            ],
        ])
        ->assertJsonCount(2, 'makes')
        ->assertJsonPath('makes.0.name', 'Toyota')
        ->assertJsonPath('makes.0.models.0.name', 'Corolla')
        ->assertJsonPath('makes.0.models.0.fuel_type', 'petrol')
        ->assertJsonPath('makes.1.name', 'BYD')
        ->assertJsonPath('makes.1.models.0.name', 'Dolphin')
        ->assertJsonPath('makes.1.models.0.fuel_type', 'electric');
});

test('can filter vehicle catalog by fuel type', function () {
    $toyota = VehicleMake::create(['name' => 'Toyota']);
    $byd = VehicleMake::create(['name' => 'BYD']);

    VehicleModel::create([
        'vehicle_make_id' => $toyota->id,
        'name' => 'Corolla',
        'fuel_type' => FuelType::Petrol,
    ]);

    VehicleModel::create([
        'vehicle_make_id' => $byd->id,
        'name' => 'Dolphin',
        'fuel_type' => FuelType::Electric,
    ]);

    $response = $this->getJson('/api/v1/vehicle-makes?fuel_type=electric');

    $response->assertOk()
        ->assertJsonCount(1, 'makes')
        ->assertJsonPath('makes.0.name', 'BYD')
        ->assertJsonPath('makes.0.models.0.name', 'Dolphin');
});

test('can list models for a specific vehicle make', function () {
    $byd = VehicleMake::create(['name' => 'BYD']);
    VehicleModel::create([
        'vehicle_make_id' => $byd->id,
        'name' => 'Dolphin',
        'fuel_type' => FuelType::Electric,
    ]);
    VehicleModel::create([
        'vehicle_make_id' => $byd->id,
        'name' => 'Atto 3',
        'fuel_type' => FuelType::Electric,
    ]);

    $response = $this->getJson("/api/v1/vehicle-makes/{$byd->id}/models");

    $response->assertOk()
        ->assertJsonPath('make.name', 'BYD')
        ->assertJsonCount(2, 'models');
});

test('seeder populates notable makes and models across different fuel types', function () {
    $this->seed(VehicleCatalogSeeder::class);

    expect(VehicleMake::where('name', 'BYD')->exists())->toBeTrue();
    expect(VehicleMake::where('name', 'Toyota')->exists())->toBeTrue();
    expect(VehicleMake::where('name', 'Tesla')->exists())->toBeTrue();
    expect(VehicleMake::where('name', 'Suzuki')->exists())->toBeTrue();
    expect(VehicleMake::where('name', 'Innoson')->exists())->toBeTrue();

    // Verify notable models with expected fuel types
    expect(VehicleModel::where('name', 'Dolphin')->first()?->fuel_type)->toBe(FuelType::Electric);
    expect(VehicleModel::where('name', 'Prius')->first()?->fuel_type)->toBe(FuelType::Hybrid);
    expect(VehicleModel::where('name', 'Prius Prime')->first()?->fuel_type)->toBe(FuelType::PlugInHybrid);
    expect(VehicleModel::where('name', 'Corolla')->first()?->fuel_type)->toBe(FuelType::Petrol);
    expect(VehicleModel::where('name', 'Dzire S-CNG')->first()?->fuel_type)->toBe(FuelType::Cng);
    expect(VehicleModel::where('name', 'Hilux')->first()?->fuel_type)->toBe(FuelType::Diesel);
});

test('operations admin can manage vehicle makes and models', function () {
    $admin = User::factory()->admin()->create([
        'admin_role' => AdminRole::Operations,
    ]);

    // Create Make
    $makeResponse = $this->actingAs($admin)
        ->postJson('/api/v1/admin/vehicle-catalog/makes', [
            'name' => 'Rivian',
        ]);

    $makeResponse->assertCreated()
        ->assertJsonPath('make.name', 'Rivian');

    $makeId = $makeResponse->json('make.id');

    // Create Model
    $modelResponse = $this->actingAs($admin)
        ->postJson("/api/v1/admin/vehicle-catalog/makes/{$makeId}/models", [
            'name' => 'R1T',
            'fuel_type' => 'electric',
        ]);

    $modelResponse->assertCreated()
        ->assertJsonPath('model.name', 'R1T')
        ->assertJsonPath('model.fuel_type', 'electric');

    $modelId = $modelResponse->json('model.id');

    // Update Model
    $this->actingAs($admin)
        ->putJson("/api/v1/admin/vehicle-catalog/models/{$modelId}", [
            'name' => 'R1T Dual Motor',
        ])
        ->assertOk()
        ->assertJsonPath('model.name', 'R1T Dual Motor');

    // Delete Model
    $this->actingAs($admin)
        ->deleteJson("/api/v1/admin/vehicle-catalog/models/{$modelId}")
        ->assertOk();

    expect(VehicleModel::find($modelId))->toBeNull();
});
