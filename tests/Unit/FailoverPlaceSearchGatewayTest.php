<?php

use App\Contracts\PlaceSearchGateway;
use App\Services\FailoverPlaceSearchGateway;
use Tests\TestCase;

uses(TestCase::class);

it('returns results from the first successful driver', function () {
    $driver1 = Mockery::mock(PlaceSearchGateway::class);
    $driver2 = Mockery::mock(PlaceSearchGateway::class);

    $driver1->shouldReceive('searchPlaces')
        ->once()
        ->with('Lekki', 6.4, 3.4, 10)
        ->andReturn([
            [
                'id' => 'p1',
                'name' => 'Lekki Phase 1',
                'address' => 'Lekki, Lagos',
                'latitude' => 6.44,
                'longitude' => 3.48,
            ],
        ]);

    $driver2->shouldNotReceive('searchPlaces');

    $failover = new FailoverPlaceSearchGateway([$driver1, $driver2]);
    $results = $failover->searchPlaces('Lekki', 6.4, 3.4, 10);

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('Lekki Phase 1');
});

it('fails over to the secondary driver when the primary throws', function () {
    $driver1 = Mockery::mock(PlaceSearchGateway::class);
    $driver2 = Mockery::mock(PlaceSearchGateway::class);

    $driver1->shouldReceive('searchPlaces')
        ->once()
        ->andThrow(new RuntimeException('Mapbox quota exceeded'));

    $driver2->shouldReceive('searchPlaces')
        ->once()
        ->andReturn([
            [
                'id' => 'geo.1',
                'name' => 'Lekki Toll Gate',
                'address' => 'Lekki, Lagos',
                'latitude' => 6.43,
                'longitude' => 3.45,
            ],
        ]);

    $failover = new FailoverPlaceSearchGateway([$driver1, $driver2]);
    $results = $failover->searchPlaces('Lekki Toll Gate');

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('Lekki Toll Gate');
});

it('fails over to the secondary driver when the primary returns empty results', function () {
    $driver1 = Mockery::mock(PlaceSearchGateway::class);
    $driver2 = Mockery::mock(PlaceSearchGateway::class);

    $driver1->shouldReceive('searchPlaces')
        ->once()
        ->andReturn([]);

    $driver2->shouldReceive('searchPlaces')
        ->once()
        ->andReturn([
            [
                'id' => 'iq.1',
                'name' => 'Lekki Mall',
                'address' => 'Lekki, Lagos',
                'latitude' => 6.45,
                'longitude' => 3.50,
            ],
        ]);

    $failover = new FailoverPlaceSearchGateway([$driver1, $driver2]);
    $results = $failover->searchPlaces('Lekki Mall');

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('Lekki Mall');
});

it('returns empty array when all drivers fail or return empty', function () {
    $driver1 = Mockery::mock(PlaceSearchGateway::class);
    $driver2 = Mockery::mock(PlaceSearchGateway::class);

    $driver1->shouldReceive('searchPlaces')->once()->andThrow(new RuntimeException('Error 1'));
    $driver2->shouldReceive('searchPlaces')->once()->andReturn([]);

    $failover = new FailoverPlaceSearchGateway([$driver1, $driver2]);
    $results = $failover->searchPlaces('Nonexistent');

    expect($results)->toBe([]);
});

it('fails over in reverseGeocode when primary driver throws', function () {
    $driver1 = Mockery::mock(PlaceSearchGateway::class);
    $driver2 = Mockery::mock(PlaceSearchGateway::class);

    $driver1->shouldReceive('reverseGeocode')
        ->once()
        ->andThrow(new RuntimeException('Rate limit'));

    $driver2->shouldReceive('reverseGeocode')
        ->once()
        ->with(6.42, 3.42)
        ->andReturn([
            'id' => 'geo.rev',
            'place_id' => 'geo.rev',
            'name' => 'Victoria Island',
            'address' => 'Victoria Island, Lagos',
            'latitude' => 6.42,
            'longitude' => 3.42,
            'city' => 'Lagos',
        ]);

    $failover = new FailoverPlaceSearchGateway([$driver1, $driver2]);
    $result = $failover->reverseGeocode(6.42, 3.42);

    expect($result['name'])->toBe('Victoria Island');
});

it('returns fallback coordinates payload when all drivers fail reverseGeocode', function () {
    $driver1 = Mockery::mock(PlaceSearchGateway::class);
    $driver1->shouldReceive('reverseGeocode')->once()->andThrow(new RuntimeException('Error'));

    $failover = new FailoverPlaceSearchGateway([$driver1]);
    $result = $failover->reverseGeocode(6.42, 3.42);

    expect($result)->toBe([
        'id' => '',
        'place_id' => '',
        'name' => '6.42,3.42',
        'address' => '6.42,3.42',
        'latitude' => 6.42,
        'longitude' => 3.42,
        'city' => null,
    ]);
});
