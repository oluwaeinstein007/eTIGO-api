<?php

use App\Console\Commands\TelemetryServerCommand;
use App\Models\Driver;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Artisan;
use Workerman\Protocols\Http\Request;

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

it('extracts token from Workerman Request query parameter', function () {
    $command = new TelemetryServerCommand;
    $raw = "GET /telemetry?token={$this->plainToken} HTTP/1.1\r\nHost: etigo-api.apeinhood.xyz\r\n\r\n";
    $request = new Request($raw);

    $token = $command->extractTokenFromHandshake($request);
    expect($token)->toBe($this->plainToken);
});

it('extracts token from Workerman Request authorization header', function () {
    $command = new TelemetryServerCommand;
    $raw = "GET /telemetry HTTP/1.1\r\nHost: etigo-api.apeinhood.xyz\r\nAuthorization: Bearer {$this->plainToken}\r\n\r\n";
    $request = new Request($raw);

    $token = $command->extractTokenFromHandshake($request);
    expect($token)->toBe($this->plainToken);
});

it('authenticates a connection object and transmits authenticated frame', function () {
    $command = new TelemetryServerCommand;
    $connection = new class
    {
        public bool $authenticated = false;

        public ?string $driverId = null;

        public ?string $userId = null;

        public array $sent = [];

        public bool $closed = false;

        public function send(string $data): void
        {
            $this->sent[] = $data;
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };

    $command->authenticateConnection($connection, $this->plainToken);

    expect($connection->authenticated)->toBeTrue()
        ->and($connection->driverId)->toBe((string) $this->driver->id)
        ->and($connection->userId)->toBe((string) $this->driverUser->id)
        ->and($connection->sent)->toHaveCount(1);

    $decoded = json_decode($connection->sent[0], true);
    expect($decoded['type'])->toBe('authenticated')
        ->and($decoded['driver_id'])->toBe((string) $this->driver->id);
});

it('refuses to stream location for a driver token past the app session lifetime', function () {
    $connection = new class
    {
        public bool $authenticated = false;

        public array $sent = [];

        public bool $closed = false;

        public function send(string $data): void
        {
            $this->sent[] = json_decode($data, true);
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };
    $this->travel(config('sanctum.app_token_expiration') + 1)->minutes();

    (new TelemetryServerCommand)->authenticateConnection($connection, $this->plainToken);

    expect($connection->authenticated)->toBeFalse()
        ->and($connection->closed)->toBeTrue()
        ->and($connection->sent[0]['code'])->toBe(401);
});
