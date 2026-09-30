<?php

use App\Contracts\MapsGateway;
use App\Models\PricingConfig;
use App\Models\SurgeRule;
use App\Services\CityDetectionService;
use App\Services\FareEstimationService;
use App\Services\SurgePricingService;

beforeEach(function () {
    $this->mockMaps = Mockery::mock(MapsGateway::class);
    $this->mockSurge = Mockery::mock(SurgePricingService::class);
    $this->mockSurge->shouldReceive('getCurrentMultiplier')
        ->andReturn(['multiplier' => 1.0, 'rule' => null])
        ->byDefault();
    $this->mockSurge->shouldReceive('applySurge')
        ->andReturnUsing(fn (float $fare, float $multiplier) => round($fare * $multiplier, 2))
        ->byDefault();

    $this->mockCityDetection = Mockery::mock(CityDetectionService::class);
    $this->mockCityDetection->shouldReceive('detectCity')
        ->andReturn(['city' => null, 'resolved_address' => null])
        ->byDefault();

    $this->service = new FareEstimationService($this->mockMaps, $this->mockSurge, $this->mockCityDetection);
});

it('calculates fare using the formula: max(minimum_fare, base_fare + distance*per_km + duration*per_minute)', function () {
    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
    ]);

    // 500 + (10 * 100) + (20 * 20) = 500 + 1000 + 400 = 1900
    $fare = $this->service->calculateFare($pricing, 10.0, 20.0);

    expect($fare)->toBe(1900.0);
});

it('applies minimum fare when calculated amount is lower', function () {
    $pricing = PricingConfig::factory()->make([
        'base_fare' => 100,
        'per_km_rate' => 10,
        'per_minute_rate' => 5,
        'minimum_fare' => 5000,
    ]);

    // 100 + (1 * 10) + (2 * 5) = 120, but minimum is 5000
    $fare = $this->service->calculateFare($pricing, 1.0, 2.0);

    expect($fare)->toBe(5000.0);
});

it('returns estimate with all required fields including surge info', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 8.5, 'duration_minutes' => 15.0]);

    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'version' => 1,
        'effective_from' => now()->subDay(),
    ]);

    $result = $this->service->estimate($pricing, 6.5244, 3.3792, 6.4541, 3.3947, 'NGN');

    expect($result)->toHaveKeys([
        'fare_estimate', 'distance_km', 'duration_minutes', 'currency', 'pricing_snapshot', 'surge',
    ]);
    expect($result['currency'])->toBe('NGN');
    expect($result['distance_km'])->toBe(8.5);
    expect($result['duration_minutes'])->toBe(15.0);
    expect($result['pricing_snapshot'])->toHaveKeys([
        'pricing_config_id', 'version', 'base_fare', 'per_km_rate',
        'per_minute_rate', 'minimum_fare', 'free_waiting_minutes',
        'effective_from', 'captured_at',
    ]);
    expect($result['waiting_time_policy'])->toHaveKeys(['free_minutes', 'per_minute_rate']);
    expect($result['surge'])->toHaveKeys(['active', 'multiplier', 'rule_name']);
    expect($result['surge']['active'])->toBeFalse();
    expect($result['surge']['multiplier'])->toBe(1.0);
});

it('applies surge multiplier to fare estimate', function () {
    $this->mockMaps->shouldReceive('getDistanceAndDuration')
        ->andReturn(['distance_km' => 10.0, 'duration_minutes' => 20.0]);

    $surgeRule = Mockery::mock(SurgeRule::class);
    $surgeRule->shouldReceive('getAttribute')->with('name')->andReturn('Rush Hour');

    $this->mockSurge->shouldReceive('getCurrentMultiplier')
        ->andReturn(['multiplier' => 1.5, 'rule' => $surgeRule]);
    $this->mockSurge->shouldReceive('applySurge')
        ->with(Mockery::type('float'), 1.5)
        ->andReturnUsing(fn (float $fare, float $multiplier) => round($fare * $multiplier, 2));

    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'version' => 1,
        'effective_from' => now()->subDay(),
    ]);

    $result = $this->service->estimate($pricing, 6.5244, 3.3792, 6.4541, 3.3947, 'NGN');

    // Base fare: 500 + (10 * 100) + (20 * 20) = 1900; with 1.5x surge = 2850
    expect($result['fare_estimate'])->toBe('2850.00');
    expect($result['surge']['active'])->toBeTrue();
    expect($result['surge']['multiplier'])->toBe(1.5);
    expect($result['surge']['rule_name'])->toBe('Rush Hour');
});

it('charges no waiting fee within free waiting period', function () {
    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'waiting_time_rate' => 15,
        'free_waiting_minutes' => 5,
    ]);

    // 3 minutes waiting is within 5 min free — no waiting charge
    $fareWithWaiting = $this->service->calculateFare($pricing, 10.0, 20.0, 3.0);
    $fareWithout = $this->service->calculateFare($pricing, 10.0, 20.0, 0.0);

    expect($fareWithWaiting)->toBe($fareWithout);
});

it('charges waiting fee after free waiting period', function () {
    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'waiting_time_rate' => 15,
        'free_waiting_minutes' => 5,
    ]);

    // 8 minutes waiting: 3 chargeable minutes × ₦15/min = ₦45
    // Base fare: 500 + (10 × 100) + (20 × 20) = 1900
    // Total: 1900 + 45 = 1945
    $fare = $this->service->calculateFare($pricing, 10.0, 20.0, 8.0);

    expect($fare)->toBe(1945.0);
});

it('charges no waiting fee when waiting_time_rate is null', function () {
    $pricing = PricingConfig::factory()->make([
        'base_fare' => 500,
        'per_km_rate' => 100,
        'per_minute_rate' => 20,
        'minimum_fare' => 700,
        'waiting_time_rate' => null,
        'free_waiting_minutes' => 5,
    ]);

    $fareWithWaiting = $this->service->calculateFare($pricing, 10.0, 20.0, 15.0);
    $fareWithout = $this->service->calculateFare($pricing, 10.0, 20.0, 0.0);

    expect($fareWithWaiting)->toBe($fareWithout);
});

it('returns zero distance fare as minimum fare', function () {
    $pricing = PricingConfig::factory()->make([
        'base_fare' => 600,
        'per_km_rate' => 250,
        'per_minute_rate' => 40,
        'minimum_fare' => 1500,
    ]);

    // 600 + (0 * 250) + (0 * 40) = 600, minimum 1500 applies
    $fare = $this->service->calculateFare($pricing, 0.0, 0.0);

    expect($fare)->toBe(1500.0);
});

it('handles very short distance ride with minimum fare', function () {
    $pricing = PricingConfig::factory()->make([
        'base_fare' => 600,
        'per_km_rate' => 250,
        'per_minute_rate' => 40,
        'minimum_fare' => 1500,
    ]);

    // 600 + (0.3 * 250) + (2 * 40) = 600 + 75 + 80 = 755, minimum 1500 applies
    $fare = $this->service->calculateFare($pricing, 0.3, 2.0);

    expect($fare)->toBe(1500.0);
});
