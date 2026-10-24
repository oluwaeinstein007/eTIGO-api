<?php

use App\Contracts\MapsGateway;
use App\Services\FailoverMapsGateway;
use App\Services\HaversineMapsGateway;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

it('routes through the next provider when one fails, ending with the straight line', function () {
    Event::fake([MessageLogged::class]);

    $google = Mockery::mock(MapsGateway::class);
    $google->shouldReceive('getDistanceAndDuration')->andThrow(new RuntimeException('HTTP request returned status code 429'));
    $mapbox = Mockery::mock(MapsGateway::class);
    $mapbox->shouldReceive('getDistanceAndDuration')->andThrow(new RuntimeException('Mapbox Directions API returned no routes.'));

    $route = (new FailoverMapsGateway([$google, $mapbox, new HaversineMapsGateway]))
        ->getDistanceAndDuration(6.6143, 3.3577, 6.4264, 3.4290);

    expect($route['distance_km'])->toBeGreaterThan(20)
        ->and($route['duration_minutes'])->toBeGreaterThan(0);

    Event::assertDispatchedTimes(MessageLogged::class, 2);
});

it('uses the first provider that answers', function () {
    $google = Mockery::mock(MapsGateway::class);
    $google->shouldReceive('reverseGeocode')->once()->andReturn(['address' => 'Allen Avenue, Ikeja', 'place_id' => 'g1', 'city' => 'Ikeja']);
    $mapbox = Mockery::mock(MapsGateway::class);
    $mapbox->shouldNotReceive('reverseGeocode');

    $result = (new FailoverMapsGateway([$google, $mapbox, new HaversineMapsGateway]))->reverseGeocode(6.6018, 3.3515);

    expect($result['address'])->toBe('Allen Avenue, Ikeja');
});

it('rethrows the last failure when every provider fails', function () {
    $google = Mockery::mock(MapsGateway::class);
    $google->shouldReceive('geocode')->andThrow(new RuntimeException('quota'));

    (new FailoverMapsGateway([$google, new HaversineMapsGateway]))->geocode('Allen Avenue, Ikeja');
})->throws(RuntimeException::class, 'Geocoding is not available with the Haversine fallback gateway.');

it('searches places with the next provider when one returns nothing', function () {
    $google = Mockery::mock(MapsGateway::class);
    $google->shouldReceive('searchPlaces')->andReturn([]);
    $mapbox = Mockery::mock(MapsGateway::class);
    $mapbox->shouldReceive('searchPlaces')->andReturn([['id' => 'm1', 'name' => 'Ikeja City Mall', 'address' => 'Alausa', 'latitude' => 6.6143, 'longitude' => 3.3577]]);

    expect((new FailoverMapsGateway([$google, $mapbox]))->searchPlaces('Ikeja City Mall'))->toHaveCount(1);
});
