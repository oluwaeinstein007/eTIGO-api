<?php

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use App\Models\VehicleClass;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->token = $this->admin->createToken('admin-auth', ['admin'])->plainTextToken;
    $this->city = City::factory()->create();
    $this->vehicleClass = VehicleClass::factory()->create();

    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);
});

it('creates a pricing config', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'base_fare' => 500.00,
            'per_km_rate' => 100.00,
            'per_minute_rate' => 20.00,
            'minimum_fare' => 700.00,
            'waiting_time_rate' => 15.00,
            'free_waiting_minutes' => 5,
            'effective_from' => now()->addDay()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJson(['message' => 'Pricing configuration created successfully.'])
        ->assertJsonPath('pricing_config.base_fare', '500.00')
        ->assertJsonPath('pricing_config.per_km_rate', '100.00')
        ->assertJsonPath('pricing_config.free_waiting_minutes', 5)
        ->assertJsonPath('pricing_config.version', 1);

    $this->assertDatabaseHas('pricing_configs', [
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => '500.00',
    ]);

    $this->assertDatabaseHas('audit_logs', ['event' => 'pricing_config_created']);
});

it('auto-increments version for same city and vehicle class', function () {
    PricingConfig::factory()->forCityAndClass($this->city, $this->vehicleClass)->create([
        'version' => 1,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'base_fare' => 600.00,
            'per_km_rate' => 120.00,
            'per_minute_rate' => 25.00,
            'minimum_fare' => 800.00,
            'effective_from' => now()->addDay()->toIso8601String(),
        ]);

    $response->assertCreated()
        ->assertJsonPath('pricing_config.version', 2);
});

it('validates required fields when creating pricing', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'city_id', 'vehicle_class_id', 'base_fare',
            'per_km_rate', 'per_minute_rate', 'minimum_fare', 'effective_from',
        ]);
});

it('rejects non-positive rates', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'base_fare' => -100,
            'per_km_rate' => -50,
            'per_minute_rate' => -10,
            'minimum_fare' => -200,
            'effective_from' => now()->addDay()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['base_fare', 'per_km_rate', 'per_minute_rate', 'minimum_fare']);
});

it('rejects past effective_from date', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/admin/pricing', [
            'city_id' => $this->city->id,
            'vehicle_class_id' => $this->vehicleClass->id,
            'base_fare' => 500,
            'per_km_rate' => 100,
            'per_minute_rate' => 20,
            'minimum_fare' => 700,
            'effective_from' => now()->subDay()->toIso8601String(),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['effective_from']);
});

it('lists pricing configs for admin', function () {
    PricingConfig::factory()->count(3)->create([
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing');

    $response->assertOk()
        ->assertJsonCount(3, 'pricing_configs')
        ->assertJsonStructure(['pricing_configs', 'meta']);
});

it('filters pricing configs by city', function () {
    $otherCity = City::factory()->create();

    PricingConfig::factory()->count(2)->create([
        'city_id' => $this->city->id,
        'created_by_admin_id' => $this->admin->id,
    ]);
    PricingConfig::factory()->create([
        'city_id' => $otherCity->id,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing?city_id='.$this->city->id);

    $response->assertOk()
        ->assertJsonCount(2, 'pricing_configs');
});

it('filters pricing configs by vehicle class', function () {
    $otherClass = VehicleClass::factory()->create();

    PricingConfig::factory()->count(2)->create([
        'vehicle_class_id' => $this->vehicleClass->id,
        'created_by_admin_id' => $this->admin->id,
    ]);
    PricingConfig::factory()->create([
        'vehicle_class_id' => $otherClass->id,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing?vehicle_class_id='.$this->vehicleClass->id);

    $response->assertOk()
        ->assertJsonCount(2, 'pricing_configs');
});

it('shows a specific pricing config', function () {
    $config = PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing/'.$config->id);

    $response->assertOk()
        ->assertJsonPath('pricing_config.id', $config->id)
        ->assertJsonStructure([
            'pricing_config' => ['id', 'city_id', 'vehicle_class_id', 'base_fare', 'per_km_rate', 'per_minute_rate', 'minimum_fare', 'version', 'effective_from'],
        ]);
});

it('returns current pricing config for city and class', function () {
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'effective_from' => now()->subDays(10),
        'version' => 1,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $current = PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'effective_from' => now()->subDay(),
        'version' => 2,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing/current?city_id='.$this->city->id.'&vehicle_class_id='.$this->vehicleClass->id);

    $response->assertOk()
        ->assertJsonPath('pricing_config.id', $current->id)
        ->assertJsonPath('pricing_config.version', 2);
});

it('returns 404 when no current pricing exists', function () {
    $response = $this->withToken($this->token)
        ->getJson('/api/v1/admin/pricing/current?city_id='.$this->city->id.'&vehicle_class_id='.$this->vehicleClass->id);

    $response->assertNotFound();
});

it('denies non-admin users', function () {
    $passenger = User::factory()->create();
    $token = $passenger->createToken('passenger-auth', ['passenger'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson('/api/v1/admin/pricing');

    $response->assertForbidden();
});
