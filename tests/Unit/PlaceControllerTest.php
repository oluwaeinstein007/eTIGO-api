<?php

use App\Contracts\MapsGateway;
use App\Contracts\PlaceSearchGateway;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Services\FailoverPlaceSearchGateway;
use App\Services\GeoapifyGateway;
use App\Services\GooglePlacesGateway;
use App\Services\HaversineMapsGateway;
use App\Services\MapboxGateway;
use App\Services\PhotonGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

test('place controller searches places and returns places list', function () {
    $gateway = Mockery::mock(MapsGateway::class);
    $gateway->shouldReceive('searchPlaces')
        ->once()
        ->with('Ikeja City Mall', 6.6, 3.3, 5)
        ->andReturn([
            [
                'id' => 'poi.123',
                'name' => 'Ikeja City Mall',
                'address' => 'Obafemi Awolowo Way, Alausa, Ikeja, Lagos',
                'latitude' => 6.6190,
                'longitude' => 3.3582,
            ],
        ]);

    $controller = new PlaceController($gateway);
    $request = Request::create('/api/v1/places/search', 'GET', [
        'q' => 'Ikeja City Mall',
        'proximity_lat' => 6.6,
        'proximity_lng' => 3.3,
        'limit' => 5,
    ]);

    $response = $controller->search($request);
    $data = $response->getData(true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKey('places')
        ->and($data['places'])->toHaveCount(1)
        ->and($data['places'][0]['name'])->toBe('Ikeja City Mall');
});

test('place controller reverse geocodes coordinates and returns place', function () {
    $gateway = Mockery::mock(MapsGateway::class);
    $gateway->shouldReceive('reverseGeocode')
        ->once()
        ->with(6.4281, 3.4219)
        ->andReturn([
            'id' => 'place.999',
            'place_id' => 'place.999',
            'name' => 'Victoria Island',
            'address' => 'Victoria Island, Lagos, Nigeria',
            'latitude' => 6.4281,
            'longitude' => 3.4219,
            'city' => 'Lagos',
        ]);

    $controller = new PlaceController($gateway);
    $request = Request::create('/api/v1/places/reverse', 'GET', [
        'lat' => 6.4281,
        'lng' => 3.4219,
    ]);

    $response = $controller->reverse($request);
    $data = $response->getData(true);

    expect($response->status())->toBe(200)
        ->and($data)->toHaveKey('place')
        ->and($data['place']['name'])->toBe('Victoria Island')
        ->and($data['place']['address'])->toBe('Victoria Island, Lagos, Nigeria');
});

test('container resolves PlaceSearchGateway according to configuration', function () {
    config()->set('services.mapbox.access_token', 'test-mapbox-token');
    config()->set('services.geoapify.api_key', 'test-geoapify-key');
    config()->set('services.places.drivers', 'mapbox,geoapify,photon');

    $resolved = app(PlaceSearchGateway::class);

    expect($resolved)->toBeInstanceOf(FailoverPlaceSearchGateway::class);
    /** @var FailoverPlaceSearchGateway $resolved */
    expect($resolved->getDrivers())->toHaveCount(3);
    expect($resolved->getDrivers()[0])->toBeInstanceOf(MapboxGateway::class);
    expect($resolved->getDrivers()[1])->toBeInstanceOf(GeoapifyGateway::class);
    expect($resolved->getDrivers()[2])->toBeInstanceOf(PhotonGateway::class);
});

test('container resolves single driver directly when only one configured', function () {
    config()->set('services.mapbox.access_token', null);
    config()->set('services.geoapify.api_key', 'test-geoapify-key');
    config()->set('services.places.drivers', 'geoapify');

    $resolved = app(PlaceSearchGateway::class);

    expect($resolved)->toBeInstanceOf(GeoapifyGateway::class);
});

test('container resolves Haversine fallback when no valid drivers configured', function () {
    config()->set('services.mapbox.access_token', null);
    config()->set('services.geoapify.api_key', null);
    config()->set('services.locationiq.api_key', null);
    config()->set('services.google_maps.api_key', null);
    config()->set('services.google.places_api_key', null);
    config()->set('services.places.drivers', 'nonexistent');

    $resolved = app(PlaceSearchGateway::class);

    expect($resolved)->toBeInstanceOf(HaversineMapsGateway::class);
});

test('container resolves GooglePlacesGateway when google driver configured', function () {
    config()->set('services.google_maps.api_key', 'test-google-key');
    config()->set('services.places.drivers', 'google');

    $resolved = app(PlaceSearchGateway::class);

    expect($resolved)->toBeInstanceOf(GooglePlacesGateway::class);
});

test('container resolves MapsGateway as GooglePlacesGateway when google api key configured', function () {
    config()->set('services.google_maps.api_key', 'test-google-key');

    $resolved = app(MapsGateway::class);

    expect($resolved)->toBeInstanceOf(GooglePlacesGateway::class);
});
