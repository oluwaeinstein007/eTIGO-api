<?php

use App\Services\MapboxGateway;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->gateway = new MapboxGateway('test-access-token');
});

it('returns distance and duration from the directions API', function () {
    Http::fake([
        'api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
            'routes' => [[
                'distance' => 12500,
                'duration' => 1200,
            ]],
        ]),
    ]);

    $result = $this->gateway->getDistanceAndDuration(6.5244, 3.3792, 6.4541, 3.3947);

    expect($result)->toBe([
        'distance_km' => 12.5,
        'duration_minutes' => 20.0,
    ]);
});

it('throws when no routes are returned', function () {
    Http::fake([
        'api.mapbox.com/directions/v5/mapbox/driving/*' => Http::response([
            'routes' => [],
        ]),
    ]);

    $this->gateway->getDistanceAndDuration(6.5244, 3.3792, 6.4541, 3.3947);
})->throws(RuntimeException::class, 'Mapbox Directions API returned no routes.');

it('geocodes an address to coordinates', function () {
    Http::fake([
        'api.mapbox.com/search/geocode/v6/forward*' => Http::response([
            'features' => [[
                'geometry' => ['coordinates' => [3.3792, 6.5244]],
                'properties' => [
                    'full_address' => 'Lagos, Nigeria',
                    'name' => 'Lagos',
                ],
            ]],
        ]),
    ]);

    $result = $this->gateway->geocode('Lagos, Nigeria');

    expect($result)->toBe([
        'lat' => 6.5244,
        'lng' => 3.3792,
        'formatted_address' => 'Lagos, Nigeria',
    ]);
});

it('reverse geocodes coordinates to an address', function () {
    Http::fake([
        'api.mapbox.com/search/geocode/v6/reverse*' => Http::response([
            'features' => [[
                'id' => 'place.12345',
                'properties' => [
                    'full_address' => 'Victoria Island, Lagos, Nigeria',
                    'context' => [
                        'place' => ['name' => 'Lagos'],
                        'locality' => ['name' => 'Victoria Island'],
                    ],
                ],
            ]],
        ]),
    ]);

    $result = $this->gateway->reverseGeocode(6.4281, 3.4219);

    expect($result)->toBe([
        'address' => 'Victoria Island, Lagos, Nigeria',
        'place_id' => 'place.12345',
        'city' => 'Lagos',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'geocode/v6/reverse')
            && $request['latitude'] == 6.4281
            && $request['longitude'] == 3.4219
            && ! isset($request['limit']);
    });
});
