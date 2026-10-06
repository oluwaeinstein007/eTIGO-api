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
        $cacheKey = "ride_eta:{$ride->id}:{$ride->status->value}";

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
}
