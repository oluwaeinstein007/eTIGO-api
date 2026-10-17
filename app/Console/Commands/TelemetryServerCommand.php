<?php

namespace App\Console\Commands;

use App\Enums\RideStatus;
use App\Events\DriverLocationUpdated;
use App\Events\NearbyMapChanged;
use App\Models\PersonalAccessToken;
use App\Models\Ride;
use App\Services\DriverLocationService;
use App\Services\EtaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

class TelemetryServerCommand extends Command
{
    protected $signature = 'telemetry:serve
                            {action=start : start|stop|restart|reload|status}
                            {--d|daemon : Run the server in daemon mode}
                            {--host= : Host to listen on}
                            {--port= : Port to listen on}
                            {--workers= : Number of worker processes}';

    protected $description = 'High-throughput WebSocket telemetry ingestion gateway for driver GPS updates';

    /**
     * In-memory cache per worker process for authenticated tokens.
     * key: sha256(token) => [driver_id, user_id, expires_at]
     *
     * @var array<string, array{driver_id: string, user_id: string, expires_at: int}>
     */
    private array $tokenCache = [];

    /**
     * In-memory cache for driver active ride IDs.
     * key: driver_id => [ride_id, cached_at]
     *
     * @var array<string, array{ride_id: ?string, cached_at: int}>
     */
    private array $activeRideCache = [];

    public function handle(DriverLocationService $locationService, EtaService $etaService): int
    {
        $action = $this->argument('action') ?: 'start';
        $host = $this->option('host') ?: config('telemetry.host', '0.0.0.0');
        $port = $this->option('port') ?: config('telemetry.port', 8081);
        $workers = (int) ($this->option('workers') ?: config('telemetry.workers', 4));

        // Format argv for Workerman's internal command parser
        global $argv;
        $argv = ['artisan', $action];
        if ($this->option('daemon')) {
            $argv[] = '-d';
        }

        Worker::$logFile = storage_path('logs/telemetry_worker.log');
        Worker::$pidFile = storage_path('logs/telemetry.pid');

        $worker = new Worker("websocket://{$host}:{$port}");
        $worker->count = $workers;
        $worker->name = 'EtigoTelemetryGateway';

        $worker->onWorkerStart = function (Worker $worker) {
            $this->info("Worker #{$worker->id} started on PID ".getmypid());
            try {
                DB::reconnect();
                Redis::purge('geolocation');
            } catch (Throwable $e) {
                $this->error('Worker start initialization warning: '.$e->getMessage());
            }
        };

        $worker->onWebSocketConnect = function (TcpConnection $connection, $request) {
            $token = $this->extractTokenFromHandshake($request);

            if ($token) {
                $this->authenticateConnection($connection, $token);
            }
        };

        $worker->onMessage = function (TcpConnection $connection, $data) use ($locationService, $etaService) {
            try {
                $payload = json_decode($data, true);
                if (! is_array($payload)) {
                    $connection->send(json_encode([
                        'type' => 'error',
                        'code' => 400,
                        'message' => 'Malformed payload; JSON object expected.',
                    ]));

                    return;
                }

                $type = $payload['type'] ?? 'location';

                if ($type === 'ping') {
                    $connection->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => time(),
                    ]));

                    return;
                }

                if ($type === 'auth') {
                    $token = $payload['token'] ?? null;
                    if (! $token) {
                        $connection->send(json_encode([
                            'type' => 'error',
                            'code' => 401,
                            'message' => 'Token required for authentication.',
                        ]));

                        return;
                    }

                    $this->authenticateConnection($connection, $token);

                    return;
                }

                if ($type === 'location') {
                    if (empty($connection->authenticated)) {
                        $connection->send(json_encode([
                            'type' => 'error',
                            'code' => 401,
                            'message' => 'Unauthenticated connection. Send an auth frame or ?token= param.',
                        ]));

                        return;
                    }

                    $this->handleLocationUpdate($connection, $payload, $locationService, $etaService);

                    return;
                }

                $connection->send(json_encode([
                    'type' => 'error',
                    'code' => 400,
                    'message' => "Unsupported message type: {$type}",
                ]));
            } catch (Throwable $e) {
                $connection->send(json_encode([
                    'type' => 'error',
                    'code' => 500,
                    'message' => 'Internal telemetry error: '.$e->getMessage(),
                ]));
            }
        };

        $worker->onClose = function (TcpConnection $connection) {
            // Connection closed; cleanup if needed
        };

        Worker::runAll();

        return self::SUCCESS;
    }

    /**
     * Extract token from handshake Request object or raw HTTP string.
     */
    public function extractTokenFromHandshake(mixed $request): ?string
    {
        if ($request instanceof Request) {
            $token = $request->get('token');
            if ($token && is_string($token)) {
                return trim($token);
            }

            $authHeader = $request->header('authorization');
            if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }

            return null;
        }

        if (is_string($request)) {
            if (preg_match('/[?&]token=([^&\s]+)/', $request, $matches)) {
                return urldecode(trim($matches[1]));
            }

            if (preg_match('/Authorization:\s*Bearer\s+([^\r\n]+)/i', $request, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Authenticate connection using Sanctum PersonalAccessToken.
     */
    public function authenticateConnection(object $connection, string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $now = time();

        // 1. Check in-process token cache (TTL 300s)
        if (isset($this->tokenCache[$tokenHash])) {
            $cached = $this->tokenCache[$tokenHash];
            if ($cached['expires_at'] > $now) {
                $connection->authenticated = true;
                $connection->driverId = $cached['driver_id'];
                $connection->userId = $cached['user_id'];
                $connection->send(json_encode([
                    'type' => 'authenticated',
                    'driver_id' => $cached['driver_id'],
                    'message' => 'Authenticated successfully.',
                ]));

                return;
            }
            unset($this->tokenCache[$tokenHash]);
        }

        // 2. Query Sanctum token model
        try {
            // The same session lifetime rules as the HTTP API apply here.
            $tokenModel = PersonalAccessToken::findToken($token);
            if (! $tokenModel || $tokenModel->hasOutlivedSession()) {
                $connection->send(json_encode([
                    'type' => 'error',
                    'code' => 401,
                    'message' => 'Invalid or expired access token.',
                ]));
                $connection->close();

                return;
            }

            $user = $tokenModel->tokenable;
            if (! $user) {
                $connection->send(json_encode([
                    'type' => 'error',
                    'code' => 401,
                    'message' => 'Associated user not found.',
                ]));
                $connection->close();

                return;
            }

            $driver = $user->driver;
            if (! $driver || ! $driver->isApproved() || ! $driver->is_online) {
                $connection->send(json_encode([
                    'type' => 'error',
                    'code' => 403,
                    'message' => 'Only online, approved drivers can stream location.',
                ]));
                $connection->close();

                return;
            }

            $driverId = (string) $driver->id;
            $userId = (string) $user->id;

            // Cache token result for 5 minutes
            $this->tokenCache[$tokenHash] = [
                'driver_id' => $driverId,
                'user_id' => $userId,
                'expires_at' => $now + 300,
            ];

            $connection->authenticated = true;
            $connection->driverId = $driverId;
            $connection->userId = $userId;

            $connection->send(json_encode([
                'type' => 'authenticated',
                'driver_id' => $driverId,
                'message' => 'Authenticated successfully.',
            ]));
        } catch (Throwable $e) {
            $connection->send(json_encode([
                'type' => 'error',
                'code' => 500,
                'message' => 'Authentication failure: '.$e->getMessage(),
            ]));
            $connection->close();
        }
    }

    /**
     * Process high-speed location update and write to Redis GEO.
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleLocationUpdate(
        TcpConnection $connection,
        array $payload,
        DriverLocationService $locationService,
        EtaService $etaService,
    ): void {
        // Enforce rate limiting: at most once every 500ms per driver connection
        $now = microtime(true);
        $minIntervalSeconds = (config('telemetry.rate_limit_ms', 500) / 1000);
        if (isset($connection->lastLocationAt) && ($now - $connection->lastLocationAt) < $minIntervalSeconds) {
            // Drop excessive pings gracefully
            return;
        }
        $connection->lastLocationAt = $now;

        $lat = isset($payload['lat']) ? (float) $payload['lat'] : null;
        $lng = isset($payload['lng']) ? (float) $payload['lng'] : null;

        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $connection->send(json_encode([
                'type' => 'error',
                'code' => 422,
                'message' => 'Invalid lat/lng coordinates.',
            ]));

            return;
        }

        $heading = isset($payload['heading']) ? (float) $payload['heading'] : 0.0;
        $speed = isset($payload['speed']) ? (float) $payload['speed'] : 0.0;
        $accuracy = isset($payload['accuracy']) && is_numeric($payload['accuracy']) && $payload['accuracy'] >= 0
            ? (float) $payload['accuracy']
            : null;
        $driverId = $connection->driverId;
        $userId = $connection->userId;

        // 1. Direct in-memory write to Redis GEO (< 0.5ms)
        $locationService->updateLocation($driverId, $lat, $lng, $heading, $speed, $userId, $accuracy);

        $location = [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            'timestamp' => time(),
        ];

        // 2. Resolve active ride with short in-memory cache (10 seconds)
        $rideId = $payload['ride_id'] ?? $this->resolveActiveRideId($driverId, $userId);
        $eta = null;
        $activeRide = null;

        if ($rideId) {
            try {
                $activeRide = Ride::find($rideId);
                if ($activeRide) {
                    $eta = $etaService->getThrottledEta($activeRide, $lat, $lng);
                }
            } catch (Throwable) {
                // Non-critical: ETA calculation must not fail ingestion
            }
        }

        // 3. Dispatch realtime broadcast to passenger / admin on private-ride.{rideId}
        try {
            DriverLocationUpdated::dispatch($driverId, $location, $rideId, $eta, $activeRide?->bookerSeesTracking() ?? true);
        } catch (Throwable) {
            // Broadcast failure must not halt ingestion
        }

        // 4. Rate-limited map invalidation: dispatch at most once every 10 seconds system-wide
        try {
            if (Cache::add('nearby_map:passengers', true, 10)) {
                NearbyMapChanged::dispatch('passengers');
            }
            if (Cache::add('nearby_map:drivers', true, 10)) {
                NearbyMapChanged::dispatch('drivers');
            }
        } catch (Throwable) {
            // Realtime map refresh must not fail location tracking
        }

        // 5. Send ACK
        $connection->send(json_encode([
            'type' => 'location_ack',
            'timestamp' => $location['timestamp'],
            'eta' => $eta,
        ]));
    }

    /**
     * Resolve the active ride ID for a driver, cached for 10 seconds.
     */
    private function resolveActiveRideId(string $driverId, string $userId): ?string
    {
        $now = time();
        if (isset($this->activeRideCache[$driverId])) {
            $entry = $this->activeRideCache[$driverId];
            if ($now - $entry['cached_at'] < 10) {
                return $entry['ride_id'];
            }
        }

        try {
            $rideId = Ride::where('driver_id', $userId)
                ->whereIn('status', [
                    RideStatus::DriverEnRoute,
                    RideStatus::DriverArrived,
                    RideStatus::InProgress,
                ])
                ->value('id');

            $this->activeRideCache[$driverId] = [
                'ride_id' => $rideId ? (string) $rideId : null,
                'cached_at' => $now,
            ];

            return $rideId ? (string) $rideId : null;
        } catch (Throwable) {
            return null;
        }
    }
}
