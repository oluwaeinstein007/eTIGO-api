<?php

use App\Contracts\MapsGateway;
use App\Http\Controllers\Api\V1\PlaceController;
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
