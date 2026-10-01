<?php

use App\Services\AppCacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    try {
        Cache::store('redis')->get('test');
    } catch (Throwable) {
        $this->markTestSkipped('Redis cache not available');
    }

    $this->cacheService = app(AppCacheService::class);
});

test('invalidateCities flushes city cache tag', function () {
    Cache::tags(['cities'])->put('test-key', 'value', 300);

    $this->cacheService->invalidateCities();

    expect(Cache::tags(['cities'])->get('test-key'))->toBeNull();
});

test('invalidateVehicleClasses flushes vehicle class cache tag', function () {
    Cache::tags(['vehicle_classes'])->put('test-key', 'value', 300);

    $this->cacheService->invalidateVehicleClasses();

    expect(Cache::tags(['vehicle_classes'])->get('test-key'))->toBeNull();
});

test('invalidatePricing flushes pricing cache tag', function () {
    Cache::tags(['pricing'])->put('test-key', 'value', 300);

    $this->cacheService->invalidatePricing();

    expect(Cache::tags(['pricing'])->get('test-key'))->toBeNull();
});

test('invalidateAll flushes all app cache tags', function () {
    Cache::tags(['cities'])->put('city-key', 'value', 300);
    Cache::tags(['vehicle_classes'])->put('vc-key', 'value', 300);
    Cache::tags(['pricing'])->put('price-key', 'value', 300);

    $this->cacheService->invalidateAll();

    expect(Cache::tags(['cities'])->get('city-key'))->toBeNull()
        ->and(Cache::tags(['vehicle_classes'])->get('vc-key'))->toBeNull()
        ->and(Cache::tags(['pricing'])->get('price-key'))->toBeNull();
});
