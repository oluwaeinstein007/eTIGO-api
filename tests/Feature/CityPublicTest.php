<?php

use App\Models\City;
use App\Models\VehicleClass;

it('lists active cities publicly', function () {
    City::factory()->count(2)->create();
    City::factory()->inactive()->create();

    $response = $this->getJson('/api/v1/cities');

    $response->assertOk()
        ->assertJsonCount(2, 'cities')
        ->assertJsonStructure(['cities' => [['id', 'name', 'slug', 'timezone', 'currency_code', 'is_active']]]);
});

it('returns empty list when no active cities exist', function () {
    City::factory()->inactive()->create();

    $response = $this->getJson('/api/v1/cities');

    $response->assertOk()
        ->assertJsonCount(0, 'cities');
});

it('lists active vehicle classes for a city', function () {
    $city = City::factory()->create();
    $activeVc = VehicleClass::factory()->create();
    $inactiveVc = VehicleClass::factory()->create();
    $globallyInactiveVc = VehicleClass::factory()->inactive()->create();

    $city->vehicleClasses()->attach([
        $activeVc->id => ['is_active' => true],
        $inactiveVc->id => ['is_active' => false],
        $globallyInactiveVc->id => ['is_active' => true],
    ]);

    $response = $this->getJson("/api/v1/cities/{$city->id}/vehicle-classes");

    $response->assertOk()
        ->assertJsonCount(1, 'vehicle_classes')
        ->assertJsonPath('vehicle_classes.0.id', $activeVc->id);
});

it('returns 404 for non-existent city vehicle classes', function () {
    $response = $this->getJson('/api/v1/cities/9999/vehicle-classes');

    $response->assertNotFound();
});
