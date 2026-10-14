<?php

use App\Services\GooglePlacesGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->gateway = new GooglePlacesGateway('test-google-key', 'ng');
});

it('returns distance and duration from the routes API', function () {
    Http::fake([
        'routes.googleapis.com/directions/v2:computeRoutes' => Http::response([
            'routes' => [[
                'distanceMeters' => 12609,
                'duration' => '1526s',
            ]],
        ]),
    ]);

    $result = $this->gateway->getDistanceAndDuration(6.5244, 3.3792, 6.4541, 3.3947);

    expect($result)->toBe([
        'distance_km' => 12.61,
        'duration_minutes' => 25.4,
    ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'routes.googleapis.com/directions/v2:computeRoutes')
            && $request->header('X-Goog-Api-Key')[0] === 'test-google-key'
            && $request['travelMode'] === 'DRIVE';
    });
});

it('throws when no routes are returned from routes API', function () {
    Http::fake([
        'routes.googleapis.com/directions/v2:computeRoutes' => Http::response([
            'routes' => [],
        ]),
    ]);

    $this->gateway->getDistanceAndDuration(6.5244, 3.3792, 6.4541, 3.3947);
})->throws(RuntimeException::class, 'Google Routes API returned no routes.');

it('geocodes an address to coordinates using Places searchText', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [[
                'formattedAddress' => 'Lagos, Nigeria',
                'location' => [
                    'latitude' => 6.5244,
                    'longitude' => 3.3792,
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

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'places:searchText')
            && $request['textQuery'] === 'Lagos, Nigeria'
            && $request['regionCode'] === 'NG'
            && $request->header('X-Goog-Api-Key')[0] === 'test-google-key';
    });
});

it('throws when address is empty in geocode', function () {
    $this->gateway->geocode('   ');
})->throws(RuntimeException::class, 'Address cannot be empty.');

it('throws when no places are returned in geocode', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [],
        ]),
    ]);

    $this->gateway->geocode('Nonexistent Place XYZ');
})->throws(RuntimeException::class, 'Google Places API returned no geocoding results for address.');

it('reverse geocodes coordinates to a place using searchNearby', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchNearby' => Http::response([
            'places' => [[
                'id' => 'ChIJ_123',
                'displayName' => ['text' => 'Victoria Island Mall'],
                'formattedAddress' => 'Ahmadu Bello Way, Victoria Island, Lagos 106104, Lagos, Nigeria',
                'location' => [
                    'latitude' => 6.4281,
                    'longitude' => 3.4219,
                ],
                'addressComponents' => [
                    [
                        'longText' => 'Lagos',
                        'types' => ['locality', 'political'],
                    ],
                    [
                        'longText' => 'Eti Osa',
                        'types' => ['administrative_area_level_2', 'political'],
                    ],
                ],
            ]],
        ]),
    ]);

    $result = $this->gateway->reverseGeocode(6.4281, 3.4219);

    expect($result)->toBe([
        'id' => 'ChIJ_123',
        'place_id' => 'ChIJ_123',
        'name' => 'Victoria Island Mall',
        'address' => 'Ahmadu Bello Way, Victoria Island, Lagos 106104, Lagos, Nigeria',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
        'city' => 'Lagos',
    ]);
});

it('retries searchNearby with larger radius when initial radius returns empty', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchNearby' => Http::sequence()
            ->push(['places' => []])
            ->push([
                'places' => [[
                    'id' => 'ChIJ_retry',
                    'displayName' => ['text' => 'Third Mainland Bridge'],
                    'formattedAddress' => 'Third Mainland Bridge, Lagos, Nigeria',
                    'location' => [
                        'latitude' => 6.4950,
                        'longitude' => 3.3980,
                    ],
                    'addressComponents' => [
                        [
                            'longText' => 'Lagos Mainland',
                            'types' => ['administrative_area_level_2', 'political'],
                        ],
                    ],
                ]],
            ]),
    ]);

    $result = $this->gateway->reverseGeocode(6.4950, 3.3980);

    expect($result['name'])->toBe('Third Mainland Bridge')
        ->and($result['city'])->toBe('Lagos Mainland');
});

it('throws when searchNearby returns empty even after retry', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchNearby' => Http::response([
            'places' => [],
        ]),
    ]);

    $this->gateway->reverseGeocode(0.0, 0.0);
})->throws(RuntimeException::class, 'Google Places API returned no results for coordinates.');

it('searches places using Places searchText', function () {
    Http::fake([
        'places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [
                [
                    'id' => 'ChIJ_mall',
                    'displayName' => ['text' => 'Ikeja City Mall'],
                    'formattedAddress' => 'Ikeja City Mall, Obafemi Awolowo Way, Alausa, Lagos, Nigeria',
                    'location' => [
                        'latitude' => 6.6190,
                        'longitude' => 3.3582,
                    ],
                ],
                [
                    'id' => 'ChIJ_bank',
                    'displayName' => ['text' => 'First Bank'],
                    'formattedAddress' => 'Agege Motor Rd, Ikeja, Lagos, Nigeria',
                    'location' => [
                        'latitude' => 6.5920,
                        'longitude' => 3.3376,
                    ],
                ],
            ],
        ]),
    ]);

    $results = $this->gateway->searchPlaces('Ikeja', 6.6, 3.3, 5);

    expect($results)->toHaveCount(2)
        ->and($results[0]['id'])->toBe('ChIJ_mall')
        ->and($results[0]['name'])->toBe('Ikeja City Mall')
        ->and($results[0]['address'])->toBe('Obafemi Awolowo Way, Alausa, Lagos, Nigeria')
        ->and($results[0]['latitude'])->toBe(6.6190)
        ->and($results[0]['longitude'])->toBe(3.3582)
        ->and($results[1]['name'])->toBe('First Bank')
        ->and($results[1]['address'])->toBe('Agege Motor Rd, Ikeja, Lagos, Nigeria');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'places:searchText')
            && $request['textQuery'] === 'Ikeja'
            && $request['pageSize'] === 5
            && $request['regionCode'] === 'NG'
            && $request['locationBias']['circle']['center']['latitude'] == 6.6;
    });
});

it('returns empty array when query is whitespace', function () {
    $results = $this->gateway->searchPlaces('   ');
    expect($results)->toBe([]);
});
