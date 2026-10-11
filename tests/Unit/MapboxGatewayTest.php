<?php

use App\Services\MapboxGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

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

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'directions/v5/mapbox/driving')
            && ($request['exclude'] ?? null) === 'ferry';
    });
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
        'id' => 'place.12345',
        'place_id' => 'place.12345',
        'name' => 'Victoria Island, Lagos, Nigeria',
        'address' => 'Victoria Island, Lagos, Nigeria',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
        'city' => 'Lagos',
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'geocode/v6/reverse')
            && $request['latitude'] == 6.4281
            && $request['longitude'] == 3.4219
            && ! isset($request['limit']);
    });
});

it('searches places using the geocoding v6 forward API', function () {
    Http::fake([
        'api.mapbox.com/search/geocode/v6/forward*' => Http::response([
            'features' => [
                [
                    'id' => 'poi.987',
                    'geometry' => ['coordinates' => [3.3582, 6.6190]],
                    'properties' => [
                        'name' => 'Ikeja City Mall',
                        'full_address' => 'Ikeja City Mall, Obafemi Awolowo Way, Ikeja, Lagos',
                    ],
                ],
            ],
        ]),
    ]);

    $results = $this->gateway->searchPlaces('Ikeja City Mall', 6.6, 3.3, 5);

    expect($results)->toHaveCount(1);
    expect($results[0])->toBe([
        'id' => 'poi.987',
        'name' => 'Ikeja City Mall',
        'address' => 'Obafemi Awolowo Way, Ikeja, Lagos',
        'latitude' => 6.6190,
        'longitude' => 3.3582,
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'geocode/v6/forward')
            && $request['q'] === 'Ikeja City Mall'
            && $request['country'] === 'ng'
            && $request['types'] === 'district,place,locality,neighborhood,address,street'
            && $request['proximity'] === '3.3,6.6'
            && $request['limit'] == 5;
    });
});
