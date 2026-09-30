<?php

use App\Services\DriverLocationService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    try {
        Redis::connection('geolocation')->command('PING');
    } catch (Throwable) {
        $this->markTestSkipped('Redis geolocation connection not available');
    }

    $this->service = app(DriverLocationService::class);

    Redis::connection('geolocation')->command('FLUSHDB');
});

test('can store and retrieve driver location', function () {
    $this->service->updateLocation(1, 6.5244, 3.3792, 90.0, 35.5);

    $location = $this->service->getDriverLocation(1);

    expect($location)
        ->not->toBeNull()
        ->and($location['lat'])->toBe(6.5244)
        ->and($location['lng'])->toBe(3.3792)
        ->and($location['heading'])->toBe(90.0)
        ->and($location['speed'])->toBe(35.5);
});

test('can find nearby drivers sorted by distance', function () {
    $this->service->updateLocation(1, 6.5244, 3.3792);
    $this->service->updateLocation(2, 6.5300, 3.3800);
    $this->service->updateLocation(3, 6.6000, 3.4000);

    $nearby = $this->service->findNearbyDrivers(6.5244, 3.3792, 5.0);

    expect($nearby)->toHaveCount(2)
        ->and($nearby[0]['driver_id'])->toBe(1)
        ->and($nearby[1]['driver_id'])->toBe(2);
});

test('remove driver clears geo and location data', function () {
    $this->service->updateLocation(1, 6.5244, 3.3792);

    $this->service->removeDriver(1);

    expect($this->service->getDriverLocation(1))->toBeNull();
    expect($this->service->findNearbyDrivers(6.5244, 3.3792, 10.0))->toBeEmpty();
});

test('returns null for unknown driver location', function () {
    expect($this->service->getDriverLocation(999))->toBeNull();
});

test('respects limit on nearby drivers', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->service->updateLocation($i, 6.5244 + ($i * 0.001), 3.3792);
    }

    $nearby = $this->service->findNearbyDrivers(6.5244, 3.3792, 10.0, 3);

    expect($nearby)->toHaveCount(3);
});
