<?php

use App\Services\LocationIqGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->gateway = new LocationIqGateway('test-locationiq-key', 'ng');
});

it('searches places using LocationIQ autocomplete API', function () {
    Http::fake([
        'us1.locationiq.com/v1/autocomplete*' => Http::response([
            [
                'place_id' => '1234567',
                'lat' => '6.6190',
                'lon' => '3.3582',
                'display_name' => 'Ikeja City Mall, Obafemi Awolowo Way, Ikeja, Lagos, Nigeria',
                'display_place' => 'Ikeja City Mall',
                'display_address' => 'Obafemi Awolowo Way, Ikeja, Lagos, Nigeria',
                'address' => [
                    'name' => 'Ikeja City Mall',
                    'city' => 'Ikeja',
                ],
            ],
        ]),
    ]);

    $results = $this->gateway->searchPlaces('Ikeja City Mall', 6.6, 3.3, 5);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBe([
            'id' => '1234567',
            'name' => 'Ikeja City Mall',
            'address' => 'Obafemi Awolowo Way, Ikeja, Lagos, Nigeria',
            'latitude' => 6.6190,
            'longitude' => 3.3582,
        ]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'v1/autocomplete')
            && $request['q'] === 'Ikeja City Mall'
            && $request['key'] === 'test-locationiq-key'
            && $request['countrycodes'] === 'ng'
            && $request['lat'] == 6.6
            && $request['lon'] == 3.3
            && $request['limit'] == 5;
    });
});

it('returns empty array when LocationIQ returns 404 for unknown query', function () {
    Http::fake([
        'us1.locationiq.com/v1/autocomplete*' => Http::response([
            'error' => 'Unable to geocode',
        ], 404),
    ]);

    $results = $this->gateway->searchPlaces('nonexistentplaceXYZ');

    expect($results)->toBe([]);
});

it('returns empty array when query is empty', function () {
    $results = $this->gateway->searchPlaces('   ');
    expect($results)->toBe([]);
});

it('reverse geocodes coordinates using LocationIQ reverse API', function () {
    Http::fake([
        'us1.locationiq.com/v1/reverse*' => Http::response([
            'place_id' => '7891011',
            'lat' => '6.4281',
            'lon' => '3.4219',
            'display_name' => 'Victoria Island, Lagos, Eti Osa, Lagos, Nigeria',
            'address' => [
                'suburb' => 'Victoria Island',
                'city' => 'Lagos',
            ],
        ]),
    ]);

    $result = $this->gateway->reverseGeocode(6.4281, 3.4219);

    expect($result)->toBe([
        'id' => '7891011',
        'place_id' => '7891011',
        'name' => 'Victoria Island',
        'address' => 'Victoria Island, Lagos, Eti Osa, Lagos, Nigeria',
        'latitude' => 6.4281,
        'longitude' => 3.4219,
        'city' => 'Lagos',
    ]);
});

it('throws RuntimeException when LocationIQ reverse geocoding returns no place_id', function () {
    Http::fake([
        'us1.locationiq.com/v1/reverse*' => Http::response([
            'error' => 'Unable to geocode',
        ], 404),
    ]);

    $this->gateway->reverseGeocode(6.4281, 3.4219);
})->throws(RuntimeException::class);
