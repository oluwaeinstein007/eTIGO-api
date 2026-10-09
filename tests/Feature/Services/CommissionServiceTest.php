<?php

use App\Models\CommissionConfig;
use App\Models\Driver;
use App\Models\User;
use App\Services\CommissionService;

beforeEach(function () {
    $this->service = app(CommissionService::class);
    $this->driverUser = User::factory()->driver()->create();
    $this->driver = Driver::factory()->approved()->create(['user_id' => $this->driverUser->id]);
});

test('getRate returns global rate when no override exists', function () {
    CommissionConfig::factory()->create(['rate' => 0.2000, 'driver_id' => null]);

    $rate = $this->service->getRate($this->driver->id);
    expect($rate)->toBe(0.2000);
});

test('getRate returns per-driver override when active', function () {
    CommissionConfig::factory()->create(['rate' => 0.2000, 'driver_id' => null]);
    CommissionConfig::factory()->create(['rate' => 0.1500, 'driver_id' => $this->driver->id]);

    $rate = $this->service->getRate($this->driver->id);
    expect($rate)->toBe(0.1500);
});

test('getRate falls back to config when no DB records', function () {
    $rate = $this->service->getRate($this->driver->id);
    expect($rate)->toBe((float) config('wallet.default_commission_rate', 0.2000));
});

test('calculate splits fare correctly', function () {
    CommissionConfig::factory()->create(['rate' => 0.2000, 'driver_id' => null]);

    $result = $this->service->calculate(500000, $this->driver->id);

    expect($result['commission'])->toBe(100000);
    expect($result['net_earnings'])->toBe(400000);
    expect($result['rate'])->toBe(0.2000);
});

test('calculate handles odd amounts with rounding', function () {
    CommissionConfig::factory()->create(['rate' => 0.2000, 'driver_id' => null]);

    $result = $this->service->calculate(333333, $this->driver->id);

    expect($result['commission'])->toBe(66667);
    expect($result['net_earnings'])->toBe(266666);
    expect($result['commission'] + $result['net_earnings'])->toBe(333333);
});
