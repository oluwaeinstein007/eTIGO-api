<?php

use App\Models\Driver;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->driverUser = User::factory()->driver()->create();
    $this->driver = Driver::factory()->approved()->for($this->driverUser)->create(['is_online' => true]);
    Vehicle::factory()->for($this->driver)->create();
    $this->plainToken = $this->driverUser->createToken('driver-auth', ['driver'])->plainTextToken;
});

it('registers the telemetry:serve artisan command', function () {
    $commands = Artisan::all();
    expect($commands)->toHaveKey('telemetry:serve');

    $command = $commands['telemetry:serve'];
    expect($command->getDescription())->toBe('High-throughput WebSocket telemetry ingestion gateway for driver GPS updates');
});

it('resolves telemetry server configurations from config', function () {
    expect(config('telemetry.port'))->toBe(8081)
        ->and(config('telemetry.host'))->toBe('0.0.0.0')
        ->and(config('telemetry.workers'))->toBe(4)
        ->and(config('telemetry.rate_limit_ms'))->toBe(500);
});

it('authenticates a valid driver sanctum token', function () {
    $tokenModel = PersonalAccessToken::findToken($this->plainToken);

    expect($tokenModel)->not->toBeNull()
        ->and($tokenModel->tokenable)->not->toBeNull()
        ->and($tokenModel->tokenable->driver)->not->toBeNull()
        ->and($tokenModel->tokenable->driver->id)->toBe($this->driver->id)
        ->and($tokenModel->tokenable->driver->isApproved())->toBeTrue()
        ->and($tokenModel->tokenable->driver->is_online)->toBeTrue();
});

it('rejects an invalid token string', function () {
    $tokenModel = PersonalAccessToken::findToken('invalid-token-string');
    expect($tokenModel)->toBeNull();
});
