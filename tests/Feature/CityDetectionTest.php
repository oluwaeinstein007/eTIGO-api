<?php

use App\Contracts\MapsGateway;
use App\Models\City;

beforeEach(function () {
    $this->mockMaps = Mockery::mock(MapsGateway::class);
    $this->app->instance(MapsGateway::class, $this->mockMaps);
});

it('detects a city from coordinates via reverse geocoding', function () {
    City::factory()->create(['name' => 'Lagos', 'is_active' => true]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->with(6.5244, 3.3792)
        ->andReturn(['address' => 'Ikeja, Lagos, Nigeria', 'place_id' => 'ChIJtest']);

    $response = $this->getJson('/api/v1/cities/detect?lat=6.5244&lng=3.3792');

    $response->assertOk()
        ->assertJsonPath('city.name', 'Lagos')
        ->assertJsonPath('resolved_address', 'Ikeja, Lagos, Nigeria');
});

it('falls back to boundary matching when name does not match', function () {
    City::factory()->create([
        'name' => 'Lagos',
        'is_active' => true,
        'boundary' => [
            'type' => 'Point',
            'coordinates' => [3.3792, 6.5244],
            'radius_km' => 50,
        ],
    ]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->andReturn(['address' => 'Some Unknown Place, Nigeria', 'place_id' => 'ChIJother']);

    $response = $this->getJson('/api/v1/cities/detect?lat=6.5244&lng=3.3792');

    $response->assertOk()
        ->assertJsonPath('city.name', 'Lagos');
});

it('returns 404 when no city matches the location', function () {
    City::factory()->create([
        'name' => 'Lagos',
        'is_active' => true,
        'boundary' => [
            'type' => 'Point',
            'coordinates' => [3.3792, 6.5244],
            'radius_km' => 5,
        ],
    ]);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->andReturn(['address' => 'Middle of Nowhere, Antarctica', 'place_id' => 'ChIJnope']);

    $response = $this->getJson('/api/v1/cities/detect?lat=-80.0&lng=0.0');

    $response->assertNotFound()
        ->assertJsonPath('message', 'No active city found for this location.');
});

it('ignores inactive cities', function () {
    City::factory()->inactive()->create(['name' => 'Lagos']);

    $this->mockMaps->shouldReceive('reverseGeocode')
        ->andReturn(['address' => 'Ikeja, Lagos, Nigeria', 'place_id' => 'ChIJtest']);

    $response = $this->getJson('/api/v1/cities/detect?lat=6.5244&lng=3.3792');

    $response->assertNotFound();
});

it('validates coordinate parameters', function () {
    $response = $this->getJson('/api/v1/cities/detect');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['lat', 'lng']);

    $response = $this->getJson('/api/v1/cities/detect?lat=91&lng=181');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['lat', 'lng']);
});
