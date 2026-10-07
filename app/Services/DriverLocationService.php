<?php

namespace App\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class DriverLocationService
{
    private const GEO_KEY = 'driver_locations';

    private const DRIVER_KEY_PREFIX = 'driver:%s:location';

    private const LOCATION_TTL = 300;

    private function redis(): Connection
    {
        return Redis::connection('geolocation');
    }

    public function updateLocation(string $driverId, float $lat, float $lng, float $heading = 0, float $speed = 0): void
    {
        $conn = $this->redis();
        $member = (string) $driverId;

        $conn->client()->rawCommand('GEOADD', self::GEO_KEY, (string) $lng, (string) $lat, $member);

        $key = sprintf(self::DRIVER_KEY_PREFIX, $driverId);
        $conn->hmset($key, [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            'timestamp' => now()->timestamp,
        ]);
        $conn->expire($key, self::LOCATION_TTL);
    }

    public function removeDriver(string $driverId): void
    {
        $conn = $this->redis();
        $member = (string) $driverId;

        $conn->zrem(self::GEO_KEY, $member);
        $conn->del(sprintf(self::DRIVER_KEY_PREFIX, $driverId));
    }

    /**
     * @return array<int, array{driver_id: int, distance_km: float}>
     */
    public function findNearbyDrivers(float $lat, float $lng, float $radiusKm, int $limit = 20): array
    {
        $results = $this->redis()->client()->rawCommand(
            'GEOSEARCH', self::GEO_KEY,
            'FROMLONLAT', (string) $lng, (string) $lat,
            'BYRADIUS', (string) $radiusKm, 'km',
            'ASC', 'COUNT', (string) $limit, 'WITHDIST',
        );

        if (! $results) {
            return [];
        }

        return array_map(fn ($result) => [
            'driver_id' => $result[0],
            'distance_km' => (float) $result[1],
        ], $results);
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
