<?php

use App\Contracts\MapsGateway;
use App\Models\PricingConfig;
use App\Services\FareEstimationService;

it('calculates fare using the formula: max(minimum_fare, base_fare + distance*per_km + duration*per_minute)', function () {
    $mockMaps = Mockery::mock(MapsGateway::class);
    $mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 10.0, 'duration_minutes' => 20.0]);

    $service = new FareEstimationService($mockMaps);

    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
    ]);

    // 500 + (10 * 100) + (20 * 20) = 500 + 1000 + 400 = 1900
    $fare = $service->calculateFare($pricing, 10.0, 20.0);

    expect($fare)->toBe(1900.0);
});

it('applies minimum fare when calculated amount is lower', function () {
    $mockMaps = Mockery::mock(MapsGateway::class);
    $service = new FareEstimationService($mockMaps);

    $pricing = PricingConfig::factory()->make([
        'base_fare' => 100,
        'per_km_rate' => 10,
        'per_minute_rate' => 5,
        'minimum_fare' => 5000,
    ]);

    // 100 + (1 * 10) + (2 * 5) = 120, but minimum is 5000
    $fare = $service->calculateFare($pricing, 1.0, 2.0);

    expect($fare)->toBe(5000.0);
});

it('returns estimate with all required fields', function () {
    $mockMaps = Mockery::mock(MapsGateway::class);
    $mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);

    $service = new FareEstimationService($mockMaps);

    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'version' => 1,
        'effective_from' => now()->subDay(),
    ]);

    $result = $service->estimate($pricing, 6.5244, 3.3792, 6.4541, 3.3947, 'NGN');

    expect($result)->toHaveKeys([
        'fare_estimate', 'distance_km', 'duration_minutes', 'currency', 'pricing_snapshot',
    ]);
    expect($result['currency'])->toBe('NGN');
    expect($result['distance_km'])->toBe(8.5);
    expect($result['duration_minutes'])->toBe(15.0);
    expect($result['pricing_snapshot'])->toHaveKeys([
        'pricing_config_id', 'version', 'base_fare', 'per_km_rate',
        'per_minute_rate', 'minimum_fare', 'effective_from', 'captured_at',
    ]);
});
