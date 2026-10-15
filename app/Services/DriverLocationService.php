<?php

namespace App\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class DriverLocationService
{
    private const GEO_KEY = 'driver_locations';

    private const DRIVER_KEY_PREFIX = 'driver:%s:location';

    private const LOCATION_TTL = 300;

    /**
     * Drivers whose last location report is older than this are not offered
     * rides or shown on maps (the app may have been killed or lost network).
     */
    public const STALE_LOCATION_SECONDS = 120;

    private function redis(): Connection
    {
        return Redis::connection('geolocation');
    }

    public function updateLocation(string $driverId, float $lat, float $lng, float $heading = 0, float $speed = 0, ?string $userId = null): void
    {
        $conn = $this->redis();
        $member = (string) $driverId;

        $conn->client()->rawCommand('GEOADD', self::GEO_KEY, (string) $lng, (string) $lat, $member);

        $data = [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            'timestamp' => now()->timestamp,
        ];

        $key = sprintf(self::DRIVER_KEY_PREFIX, $driverId);
        $conn->hmset($key, $data);
        $conn->expire($key, self::LOCATION_TTL);

        if ($userId !== null && $userId !== $driverId) {
            $userKey = sprintf(self::DRIVER_KEY_PREFIX, $userId);
            $conn->hmset($userKey, $data);
            $conn->expire($userKey, self::LOCATION_TTL);
        }
    }

    public function removeDriver(string $driverId, ?string $userId = null): void
    {
        $conn = $this->redis();
        $member = (string) $driverId;

        $conn->zrem(self::GEO_KEY, $member);
        $conn->del(sprintf(self::DRIVER_KEY_PREFIX, $driverId));

        if ($userId !== null && $userId !== $driverId) {
            $conn->del(sprintf(self::DRIVER_KEY_PREFIX, $userId));
        }
    }

    /**
     * @return array<int, array{driver_id: string, distance_km: float}>
     */
    public function findNearbyDrivers(float $lat, float $lng, float $radiusKm, int $limit = 20): array
    {
        $conn = $this->redis();

        $results = $conn->client()->rawCommand(
            'GEOSEARCH', self::GEO_KEY,
            'FROMLONLAT', (string) $lng, (string) $lat,
            'BYRADIUS', (string) $radiusKm, 'km',
            'ASC', 'COUNT', (string) $limit, 'WITHDIST',
        );

        if (! $results) {
            return [];
        }

        $freshAfter = now()->subSeconds(self::STALE_LOCATION_SECONDS)->timestamp;
        $drivers = [];

        foreach ($results as [$member, $distance]) {
            $reportedAt = (int) $conn->hget(sprintf(self::DRIVER_KEY_PREFIX, $member), 'timestamp');

            if ($reportedAt === 0) {
                // The location hash expired: the app stopped reporting without
                // going offline. Drop the ghost member from the geo index.
                $conn->zrem(self::GEO_KEY, $member);

                continue;
            }

            if ($reportedAt < $freshAfter) {
                continue;
            }

            $drivers[] = [
                'driver_id' => (string) $member,
                'distance_km' => (float) $distance,
            ];
        }

        return $drivers;
    }

    /**
     * @return array{lat: float, lng: float, heading: float, speed: float, timestamp: int}|null
     */
    public function getDriverLocation(string $driverId): ?array
    {
        $key = sprintf(self::DRIVER_KEY_PREFIX, $driverId);
        $data = $this->redis()->hgetall($key);

        if (! $data || empty($data['lat'])) {
            return null;
        }

        return [
            'lat' => (float) $data['lat'],
            'lng' => (float) $data['lng'],
            'heading' => (float) ($data['heading'] ?? 0),
            'speed' => (float) ($data['speed'] ?? 0),
            'timestamp' => (int) ($data['timestamp'] ?? 0),
        ];
    }
}
