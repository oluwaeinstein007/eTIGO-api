<?php

use App\Services\GoogleMapsGateway;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->gateway = new GoogleMapsGateway('test-api-key');
});

it('returns distance and duration from the distance matrix API', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
            'status' => 'OK',
            'rows' => [[
                'elements' => [[
                    'status' => 'OK',
                    'distance' => ['value' => 12500],
                    'duration' => ['value' => 1200],
                ]],
            ]],
        ]),
    ]);

    $result = $this->gateway->getDistanceAndDuration(6.5244, 3.3792, 6.4541, 3.3947);

    expect($result)->toBe([
        'distance_km' => 12.5,
        'duration_minutes' => 20.0,
    ]);
});

it('throws when the API returns a non-OK status', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
            'status' => 'REQUEST_DENIED',
        ]),
    ]);

    $this->gateway->getDistanceAndDuration(6.5244, 3.3792, 6.4541, 3.3947);
})->throws(RuntimeException::class, 'Google Maps API error: REQUEST_DENIED');

it('throws when no route is found', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/distancematrix/*' => Http::response([
            'status' => 'OK',
            'rows' => [[
                'elements' => [[
                    'status' => 'ZERO_RESULTS',
                ]],
            ]],
        ]),
    ]);

    $this->gateway->getDistanceAndDuration(6.5244, 3.3792, 6.4541, 3.3947);
})->throws(RuntimeException::class, 'Google Maps API returned no route');

it('geocodes an address to coordinates', function () {
    Http::fake([
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'geometry' => ['location' => ['lat' => 6.5244, 'lng' => 3.3792]],
                'formatted_address' => 'Lagos, Nigeria',
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
        'maps.googleapis.com/maps/api/geocode/*' => Http::response([
            'status' => 'OK',
            'results' => [[
                'formatted_address' => 'Victoria Island, Lagos, Nigeria',
                'place_id' => 'ChIJwYCC5iqOOxARy9nDZ6OHntw',
            ]],
        ]),
    ]);

    $result = $this->gateway->reverseGeocode(6.4281, 3.4219);

    expect($result)->toBe([
        'address' => 'Victoria Island, Lagos, Nigeria',
        'place_id' => 'ChIJwYCC5iqOOxARy9nDZ6OHntw',
    ]);
});
