<?php

use App\Services\PhotonGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->gateway = new PhotonGateway('https://photon.komoot.io');
});

it('searches places using Photon API', function () {
    Http::fake([
        'photon.komoot.io/api/*' => Http::response([
            'features' => [
                [
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [3.3582, 6.6190],
                    ],
                    'properties' => [
                        'osm_id' => 999888,
                        'name' => 'Ikeja City Mall',
                        'street' => 'Obafemi Awolowo Way',
                        'city' => 'Ikeja',
                        'state' => 'Lagos',
                        'country' => 'Nigeria',
                    ],
                ],
            ],
        ]),
    ]);

    $results = $this->gateway->searchPlaces('Ikeja City Mall', 6.6, 3.3, 5);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBe([
            'id' => 'osm.999888',
            'name' => 'Ikeja City Mall',
            'address' => 'Obafemi Awolowo Way, Ikeja, Lagos, Nigeria',
            'latitude' => 6.6190,
            'longitude' => 3.3582,
        ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'photon.komoot.io/api')
            && $request['q'] === 'Ikeja City Mall'
            && $request['lat'] == 6.6
            && $request['lon'] == 3.3
            && $request['limit'] == 5;
    });
});

it('returns empty array when query is empty', function () {
    $results = $this->gateway->searchPlaces('   ');
    expect($results)->toBe([]);
});

it('reverse geocodes coordinates using Photon reverse API', function () {
    Http::fake([
        'photon.komoot.io/reverse/*' => Http::response([
            'features' => [
                [
                    'properties' => [
                        'osm_id' => 777666,
                        'name' => 'Victoria Island',
                        'district' => 'Eti-Osa',
                        'city' => 'Lagos',
                        'state' => 'Lagos',
                        'country' => 'Nigeria',
                    ],
                ],
            ],
        ]),
    ]);

    $result = $this->gateway->reverseGeocode(6.4281, 3.4219);

    expect($result)->toBe([
        'id' => 'osm.777666',
        'place_id' => 'osm.777666',
        'name' => 'Victoria Island',
        'address' => 'Eti-Osa, Lagos, Lagos, Nigeria',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
        'city' => 'Lagos',
    ]);
});

it('throws RuntimeException when Photon reverse geocoding returns no results', function () {
    Http::fake([
        'photon.komoot.io/reverse/*' => Http::response([
            'features' => [],
        ]),
    ]);

    $this->gateway->reverseGeocode(6.4281, 3.4219);
})->throws(RuntimeException::class, 'Photon Reverse Geocoding API returned no results.');
