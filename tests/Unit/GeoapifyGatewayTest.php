<?php

use App\Services\GeoapifyGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->gateway = new GeoapifyGateway('test-geoapify-key', 'ng');
});

it('searches places using Geoapify autocomplete API', function () {
    Http::fake([
        'api.geoapify.com/v1/geocode/autocomplete*' => Http::response([
            'features' => [
                [
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [3.3582, 6.6190],
                    ],
                    'properties' => [
                        'place_id' => 'geo.123',
                        'name' => 'Ikeja City Mall',
                        'formatted' => 'Ikeja City Mall, Obafemi Awolowo Way, Ikeja, Lagos, Nigeria',
                        'address_line1' => 'Ikeja City Mall',
                        'address_line2' => 'Obafemi Awolowo Way, Ikeja, Lagos',
                        'city' => 'Ikeja',
                    ],
                ],
            ],
        ]),
    ]);

    $results = $this->gateway->searchPlaces('Ikeja City Mall', 6.6, 3.3, 5);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBe([
            'id' => 'geo.123',
            'name' => 'Ikeja City Mall',
            'address' => 'Obafemi Awolowo Way, Ikeja, Lagos',
            'latitude' => 6.6190,
            'longitude' => 3.3582,
        ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'geocode/autocomplete')
            && $request['text'] === 'Ikeja City Mall'
            && $request['apiKey'] === 'test-geoapify-key'
            && $request['filter'] === 'countrycode:ng'
            && $request['bias'] === 'proximity:3.3,6.6'
            && $request['limit'] == 5;
    });
});

it('returns empty array when query is empty', function () {
    $results = $this->gateway->searchPlaces('   ');
    expect($results)->toBe([]);
});

it('reverse geocodes coordinates using Geoapify reverse API', function () {
    Http::fake([
        'api.geoapify.com/v1/geocode/reverse*' => Http::response([
            'features' => [
                [
                    'properties' => [
                        'place_id' => 'geo.999',
                        'name' => 'Victoria Island',
                        'formatted' => 'Victoria Island, Lagos, Nigeria',
                        'city' => 'Lagos',
                    ],
                ],
            ],
        ]),
    ]);

    $result = $this->gateway->reverseGeocode(6.4281, 3.4219);

    expect($result)->toBe([
        'id' => 'geo.999',
        'place_id' => 'geo.999',
        'name' => 'Victoria Island',
        'address' => 'Victoria Island, Lagos, Nigeria',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
        'city' => 'Lagos',
    ]);
});

it('throws RuntimeException when Geoapify reverse geocoding returns no results', function () {
    Http::fake([
        'api.geoapify.com/v1/geocode/reverse*' => Http::response([
            'features' => [],
        ]),
    ]);

    $this->gateway->reverseGeocode(6.4281, 3.4219);
})->throws(RuntimeException::class, 'Geoapify Reverse Geocoding API returned no results.');
