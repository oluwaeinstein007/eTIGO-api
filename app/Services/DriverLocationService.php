<?php

namespace App\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

class DriverLocationService
{
    private const GEO_KEY = 'driver_locations';

    private const DRIVER_KEY_PREFIX = 'driver:%s:location';

    private const LOCATION_TTL = 300;

    /** When the driver's app last sent a location; outlives the location itself. */
    private const LAST_SEEN_KEY_PREFIX = 'driver:%s:last_seen';

    private const LAST_SEEN_TTL = 86400;

    /**
     * Drivers whose last location report is older than this are not offered
     * rides or shown on maps (the app may have been killed or lost network).
     */
    public const STALE_LOCATION_SECONDS = 120;

    private function redis(): Connection
    {
        return Redis::connection('geolocation');
    }

    /**
     * @param  float|null  $accuracy  Horizontal GPS accuracy in metres, as the phone reports it
     */
    public function updateLocation(string $driverId, float $lat, float $lng, float $heading = 0, float $speed = 0, ?string $userId = null, ?float $accuracy = null): void
    {
        $conn = $this->redis();
        $member = (string) $driverId;

        $conn->client()->rawCommand('GEOADD', self::GEO_KEY, (string) $lng, (string) $lat, $member);

        $data = [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            // Always written, so an app that stops reporting accuracy does not
            // leave an old value behind; empty means unknown.
            'accuracy' => $accuracy ?? '',
            'timestamp' => now()->timestamp,
        ];

        $key = sprintf(self::DRIVER_KEY_PREFIX, $driverId);
        $conn->hmset($key, $data);
        $conn->expire($key, self::LOCATION_TTL);
        $conn->setex(sprintf(self::LAST_SEEN_KEY_PREFIX, $driverId), self::LAST_SEEN_TTL, $data['timestamp']);

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
            'accuracy' => isset($data['accuracy']) && $data['accuracy'] !== '' ? (float) $data['accuracy'] : null,
            'timestamp' => (int) ($data['timestamp'] ?? 0),
        ];
    }

    /**
     * getDriverLocation() for many drivers in one round trip; drivers with no
     * live location are left out.
     *
     * @param  list<string>  $driverIds
     * @return array<string, array{lat: float, lng: float, heading: float, speed: float, timestamp: int}>
     */
    public function getDriverLocations(array $driverIds): array
    {
        $driverIds = array_values(array_unique(array_filter($driverIds)));

        if ($driverIds === []) {
            return [];
        }

        $results = $this->redis()->pipeline(function ($pipe) use ($driverIds) {
            foreach ($driverIds as $driverId) {
                $pipe->hgetall(sprintf(self::DRIVER_KEY_PREFIX, $driverId));
            }
        });

        $results = is_array($results) ? $results : [];
        $locations = [];
        foreach ($driverIds as $i => $driverId) {
            $data = $results[$i] ?? null;

            if (! is_array($data) || empty($data['lat'])) {
                continue;
            }

            $locations[$driverId] = [
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lng'],
                'heading' => (float) ($data['heading'] ?? 0),
                'speed' => (float) ($data['speed'] ?? 0),
                'timestamp' => (int) ($data['timestamp'] ?? 0),
            ];
        }

        return $locations;
    }

    /**
     * When the driver's app last sent a location, kept for a day; null if
     * it has not sent one since. Falls back to the live location, which a
     * driver may have without the last-seen key (sent before it existed).
     */
    public function lastSeenAt(string $driverId): ?Carbon
    {
        $timestamp = $this->redis()->get(sprintf(self::LAST_SEEN_KEY_PREFIX, $driverId))
            ?: $this->getDriverLocation($driverId)['timestamp'] ?? null;

        return $timestamp ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }
}
