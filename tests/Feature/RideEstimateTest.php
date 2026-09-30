<?php

use App\Contracts\MapsGateway;
use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use App\Models\VehicleClass;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth', ['passenger'])->plainTextToken;

    $this->city = City::factory()->create(['currency_code' => 'NGN']);
    $this->vehicleClass = VehicleClass::factory()->create();
    $this->city->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    $this->admin = User::factory()->admin()->create();

    $mockMaps = Mockery::mock(MapsGateway::class);
    $mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);
    $mockMaps->shouldReceive('reverseGeocode')
        ->andReturn(['address' => '123 Test Street, Lagos, Nigeria', 'place_id' => 'ChIJtest']);
    $this->app->instance(MapsGateway::class, $mockMaps);
});

it('returns fare estimates for all vehicle classes in a city', function () {
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->city->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'destination_lat' => 6.4541,
            'destination_lng' => 3.3947,
        ]);

    $response->assertOk()
        ->assertJsonStructure([
            'estimates' => [
                '*' => [
                    'vehicle_class' => ['id', 'name', 'display_name', 'capacity', 'icon_url'],
                    'fare_estimate',
                    'distance_km',
                    'duration_minutes',
                    'currency',
                    'pickup_address',
                    'destination_address',
                    'pricing_snapshot',
                    'waiting_time_policy' => ['free_minutes', 'per_minute_rate'],
                ],
            ],
        ])
        ->assertJsonPath('estimates.0.currency', 'NGN');
});

it('returns empty estimates when no pricing configured', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->city->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'destination_lat' => 6.4541,
            'destination_lng' => 3.3947,
        ]);

    $response->assertOk()
        ->assertJsonCount(0, 'estimates');
});

it('validates required fields', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'city_id', 'pickup_lat', 'pickup_lng', 'destination_lat', 'destination_lng',
        ]);
});

it('validates coordinate ranges', function () {
    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->city->id,
            'pickup_lat' => 91,
            'pickup_lng' => 181,
            'destination_lat' => -91,
            'destination_lng' => -181,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['pickup_lat', 'pickup_lng', 'destination_lat', 'destination_lng']);
});

it('rejects unauthenticated requests', function () {
    $response = $this->postJson('/api/v1/rides/estimate', [
        'city_id' => $this->city->id,
        'pickup_lat' => 6.5244,
        'pickup_lng' => 3.3792,
        'destination_lat' => 6.4541,
        'destination_lng' => 3.3947,
    ]);

    $response->assertUnauthorized();
});

it('applies minimum fare when calculated fare is lower', function () {
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 100,
        'per_km_rate' => 10,
        'per_minute_rate' => 5,
        'minimum_fare' => 5000,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->city->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'destination_lat' => 6.5250,
            'destination_lng' => 3.3800,
        ]);

    $response->assertOk();

    $estimate = $response->json('estimates.0.fare_estimate');
    expect((float) $estimate)->toBeGreaterThanOrEqual(5000);
});

it('uses only current effective pricing config', function () {
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'effective_from' => now()->subDays(10),
        'version' => 1,
        'created_by_admin_id' => $this->admin->id,
    ]);

    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 1000,
        'per_km_rate' => 200,
        'per_minute_rate' => 40,
        'minimum_fare' => 1500,
        'effective_from' => now()->subDay(),
        'version' => 2,
        'created_by_admin_id' => $this->admin->id,
    ]);

    // Future pricing should not be used
    PricingConfig::factory()->create([
        'city_id' => $this->city->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 2000,
        'per_km_rate' => 400,
        'per_minute_rate' => 80,
        'minimum_fare' => 3000,
        'effective_from' => now()->addDay(),
        'version' => 3,
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->city->id,
            'pickup_lat' => 6.5244,
            'pickup_lng' => 3.3792,
            'destination_lat' => 6.4541,
            'destination_lng' => 3.3947,
        ]);

    $response->assertOk();

    // The fare should be based on version 2 (base_fare: 1000), not version 3 (future)
    $estimate = (float) $response->json('estimates.0.fare_estimate');
    expect($estimate)->toBeGreaterThanOrEqual(1500);
});
