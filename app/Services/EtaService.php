<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Enums\RideStatus;
use App\Models\Ride;
use Illuminate\Support\Facades\Cache;

class EtaService
{
    private const THROTTLE_SECONDS = 30;

    public function __construct(
        private MapsGateway $mapsGateway,
        private DriverLocationService $locationService,
    ) {}

    /**
     * @return array{distance_km: float, duration_minutes: float}|null
     */
    public function getEtaForRide(Ride $ride, ?float $driverLat = null, ?float $driverLng = null): ?array
    {
        if ($driverLat === null || $driverLng === null) {
            if (! $ride->driver_id) {
                return null;
            }

            $location = $this->locationService->getDriverLocation($ride->driver_id);
            if (! $location) {
                return null;
            }

            $driverLat = $location['lat'];
            $driverLng = $location['lng'];
        }

        $destinationLat = match ($ride->status) {
            RideStatus::DriverEnRoute, RideStatus::Matched => $ride->pickup_lat,
            RideStatus::DriverArrived => $ride->pickup_lat,
            RideStatus::InProgress => $ride->destination_lat,
            default => null,
        };

        $destinationLng = match ($ride->status) {
            RideStatus::DriverEnRoute, RideStatus::Matched => $ride->pickup_lng,
            RideStatus::DriverArrived => $ride->pickup_lng,
            RideStatus::InProgress => $ride->destination_lng,
            default => null,
        };

        if ($destinationLat === null) {
            return null;
        }

        return $this->mapsGateway->getDistanceAndDuration(
            (float) $driverLat,
            (float) $driverLng,
            (float) $destinationLat,
            (float) $destinationLng,
        );
    }

    /**
     * @return array{distance_km: float, duration_minutes: float}|null
     */
    public function getThrottledEta(Ride $ride, float $driverLat, float $driverLng): ?array
    {
        $cacheKey = $this->throttleCacheKey($ride);

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $eta = $this->getEtaForRide($ride, $driverLat, $driverLng);

        if ($eta) {
            Cache::put($cacheKey, $eta, self::THROTTLE_SECONDS);
        }

        return $eta;
    }

    /**
     * ETA for the admin monitoring views: to the pickup while the driver is on
     * the way, to the destination while the trip is in progress. It reuses the
     * ETA last computed from the driver's location reports and otherwise falls
     * back to a straight-line estimate, so listing rides never calls the
     * routing provider.
     *
     * @param  array{lat: float, lng: float}|null  $driverLocation
     * @return array{target: string, distance_km: float, duration_minutes: float}|null
     */
    public function getMonitoringEta(Ride $ride, ?array $driverLocation): ?array
    {
        $target = match ($ride->status) {
            RideStatus::Matched, RideStatus::DriverEnRoute => 'pickup',
            RideStatus::InProgress => 'destination',
            default => null,
        };

        if ($target === null || $driverLocation === null) {
            return null;
        }

        $eta = Cache::get($this->throttleCacheKey($ride));

        if ($eta === null) {
            [$targetLat, $targetLng] = $target === 'pickup'
                ? [$ride->pickup_lat, $ride->pickup_lng]
                : [$ride->destination_lat, $ride->destination_lng];

            $eta = (new HaversineMapsGateway)->getDistanceAndDuration(
                (float) $driverLocation['lat'],
                (float) $driverLocation['lng'],
                (float) $targetLat,
                (float) $targetLng,
            );
        }

        return [
            'target' => $target,
            'distance_km' => (float) $eta['distance_km'],
            'duration_minutes' => (float) $eta['duration_minutes'],
        ];
    }

    private function throttleCacheKey(Ride $ride): string
    {
        return "ride_eta:{$ride->id}:{$ride->status->value}";
    }
}
