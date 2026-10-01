<?php

use App\Contracts\MapsGateway;
use App\Models\City;
use App\Models\PricingConfig;
use App\Models\User;
use App\Models\VehicleClass;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('auth', ['passenger'])->plainTextToken;
    $this->admin = User::factory()->admin()->create();

    $this->pickupCity = City::factory()->create([
        'name' => 'Abuja',
        'currency_code' => 'NGN',
        'boundary' => [
            'type' => 'Point',
            'coordinates' => [7.4951, 9.0579],
            'radius_km' => 40,
        ],
    ]);

    $this->vehicleClass = VehicleClass::factory()->create();
    $this->pickupCity->vehicleClasses()->attach($this->vehicleClass->id, ['is_active' => true]);

    PricingConfig::factory()->create([
        'city_id' => $this->pickupCity->id,
        'vehicle_class_id' => $this->vehicleClass->id,
        'base_fare' => 600,
        'per_km_rate' => 250,
        'per_minute_rate' => 40,
        'minimum_fare' => 1500,
        'waiting_time_rate' => 50,
        'free_waiting_minutes' => 5,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    $this->mockMaps = Mockery::mock(MapsGateway::class);
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->andReturn(['address' => '123 Test Street, Abuja, Nigeria', 'place_id' => 'ChIJtest'])
        ->byDefault();
    $this->app->instance(MapsGateway::class, $this->mockMaps);
});

it('rejects estimate for an inactive city', function () {
    $inactiveCity = City::factory()->inactive()->create();
    $vc = VehicleClass::factory()->create();
    $inactiveCity->vehicleClasses()->attach($vc->id, ['is_active' => true]);

    PricingConfig::factory()->create([
        'city_id' => $inactiveCity->id,
        'vehicle_class_id' => $vc->id,
        'effective_from' => now()->subDay(),
        'created_by_admin_id' => $this->admin->id,
    ]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $inactiveCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.0765,
            'destination_lng' => 7.3986,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['city_id']);
});

it('rejects estimate when pickup and destination are the same location', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 0.0, 'duration_minutes' => 0.0]);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.0579,
            'destination_lng' => 7.4951,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['destination_lat']);
});

it('detects cross-city ride and includes warning', function () {
    $destCity = City::factory()->create([
        'name' => 'Lagos',
        'currency_code' => 'NGN',
        'boundary' => [
            'type' => 'Point',
            'coordinates' => [3.3792, 6.5244],
            'radius_km' => 40,
        ],
    ]);

    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 850.0, 'duration_minutes' => 600.0]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0579, 7.4951)
        ->andReturn(['address' => '1 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJpickup']);
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(6.5244, 3.3792)
        ->andReturn(['address' => '1 Marina, Lagos, Nigeria', 'place_id' => 'ChIJdest']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 6.5244,
            'destination_lng' => 3.3792,
        ]);

    $response->assertOk();

    expect($response->json('warnings'))->toBeArray();
    expect($response->json('warnings'))->toContain(
        'This is a cross-city ride from Abuja to Lagos. Pickup city (Abuja) pricing applies for this trip.'
    );
    expect($response->json('cross_city'))->not->toBeNull();
    expect($response->json('cross_city.destination_city_name'))->toBe('Lagos');
    expect($response->json('cross_city.pickup_city_name'))->toBe('Abuja');
});

it('warns when destination is outside all service areas', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 200.0, 'duration_minutes' => 180.0]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0579, 7.4951)
        ->andReturn(['address' => '1 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJpickup']);
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(7.7333, 4.1833)
        ->andReturn(['address' => '1 Main St, Osogbo, Nigeria', 'place_id' => 'ChIJdest']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 7.7333,
            'destination_lng' => 4.1833,
        ]);

    $response->assertOk();

    $warnings = $response->json('warnings');
    expect($warnings)->toContain(
        'Your destination is outside our current service areas. Pickup city pricing applies. The driver may not be able to accept return trips from the destination.'
    );
});

it('warns for long-distance rides over 100km', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 150.0, 'duration_minutes' => 120.0]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0579, 7.4951)
        ->andReturn(['address' => '1 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJpickup']);
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.9, 8.9)
        ->andReturn(['address' => '1 Main St, Abuja, Nigeria', 'place_id' => 'ChIJdest']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.9,
            'destination_lng' => 8.9,
        ]);

    $response->assertOk();

    expect($response->json('warnings'))->toContain(
        'This is a long-distance ride (over 100 km). Fare is estimated based on route distance and may vary.'
    );
});

it('uses pickup city pricing for cross-city rides', function () {
    City::factory()->create([
        'name' => 'Lagos',
        'currency_code' => 'NGN',
        'boundary' => [
            'type' => 'Point',
            'coordinates' => [3.3792, 6.5244],
            'radius_km' => 40,
        ],
    ]);

    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 850.0, 'duration_minutes' => 600.0]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0579, 7.4951)
        ->andReturn(['address' => '1 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJpickup']);
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(6.5244, 3.3792)
        ->andReturn(['address' => '1 Marina, Lagos, Nigeria', 'place_id' => 'ChIJdest']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 6.5244,
            'destination_lng' => 3.3792,
        ]);

    $response->assertOk();

    // Fare uses Abuja pricing: 600 + (850 * 250) + (600 * 40) = 600 + 212500 + 24000 = 237100
    $estimate = (float) $response->json('estimates.0.fare_estimate');
    expect($estimate)->toBe(237100.0);
    expect($response->json('estimates.0.currency'))->toBe('NGN');
});

it('returns no warnings for standard same-city rides', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 10.0, 'duration_minutes' => 25.0]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0579, 7.4951)
        ->andReturn(['address' => '1 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJpickup']);
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0765, 7.3986)
        ->andReturn(['address' => '2 Main St, Abuja, Nigeria', 'place_id' => 'ChIJdest']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.0765,
            'destination_lng' => 7.3986,
        ]);

    $response->assertOk();
    expect($response->json('warnings'))->toBeNull();
    expect($response->json('cross_city'))->toBeNull();
});

it('applies minimum fare for very short distance rides', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 0.2, 'duration_minutes' => 1.0]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0579, 7.4951)
        ->andReturn(['address' => '1 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJpickup']);
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0585, 7.4955)
        ->andReturn(['address' => '2 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJdest']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 9.0585,
            'destination_lng' => 7.4955,
        ]);

    $response->assertOk();

    // 600 + (0.2 * 250) + (1 * 40) = 600 + 50 + 40 = 690, minimum 1500 applies
    $estimate = (float) $response->json('estimates.0.fare_estimate');
    expect($estimate)->toBe(1500.0);
});

it('handles multiple warnings simultaneously', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 150.0, 'duration_minutes' => 120.0]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(9.0579, 7.4951)
        ->andReturn(['address' => '1 Eagle Square, Abuja, Nigeria', 'place_id' => 'ChIJpickup']);
    // Destination is in an unrecognized city
    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(7.7333, 4.1833)
        ->andReturn(['address' => '1 Main St, Osogbo, Nigeria', 'place_id' => 'ChIJdest']);

    $response = $this->withToken($this->token)
        ->postJson('/api/v1/rides/estimate', [
            'city_id' => $this->pickupCity->id,
            'pickup_lat' => 9.0579,
            'pickup_lng' => 7.4951,
            'destination_lat' => 7.7333,
            'destination_lng' => 4.1833,
        ]);

    $response->assertOk();

    // Should have both: outside service area + long distance
    $warnings = $response->json('warnings');
    expect($warnings)->toHaveCount(2);
});
