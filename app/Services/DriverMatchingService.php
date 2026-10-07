<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Models\Driver;
use App\Models\Ride;
use Illuminate\Support\Facades\Cache;

class DriverMatchingService
{
    private const REJECTED_DRIVERS_KEY = 'ride:%s:rejected_drivers';

    private const DISPATCHED_DRIVER_KEY = 'ride:%s:dispatched_to';

    private const RADIUS_STEP_KEY = 'ride:%s:radius_step';

    public function __construct(
        private DriverLocationService $locationService,
    ) {}

    /**
     * @return array<int, array{driver_id: int, user_id: int, distance_km: float}>
     */
    public function findEligibleDrivers(Ride $ride, float $radiusKm): array
    {
        $nearby = $this->locationService->findNearbyDrivers(
            (float) $ride->pickup_lat,
            (float) $ride->pickup_lng,
            $radiusKm,
            config('matching.max_candidates_per_search', 20),
        );

        if (empty($nearby)) {
            return [];
        }

        $rejectedIds = $this->getRejectedDriverIds($ride);
        $dispatchedTo = $this->getDispatchedDriverId($ride);

        $nearbyDriverModelIds = array_column($nearby, 'driver_id');
        $distanceMap = [];
        foreach ($nearby as $entry) {
            $distanceMap[$entry['driver_id']] = $entry['distance_km'];
        }

        $eligible = Driver::with('vehicle')
            ->whereIn('id', $nearbyDriverModelIds)
            ->where('status', DriverStatus::Approved)
            ->where('is_online', true)
            ->whereHas('vehicle', fn ($q) => $q->where('vehicle_class_id', $ride->vehicle_class_id))
            ->whereNotIn('user_id', $rejectedIds)
            ->when($dispatchedTo, fn ($q) => $q->where('user_id', '!=', $dispatchedTo))
            ->whereDoesntHave('user', function ($q) {
                $q->whereHas('driverRides', fn ($r) => $r->whereIn('status', [
                    RideStatus::Matched,
                    RideStatus::DriverEnRoute,
                    RideStatus::DriverArrived,
                    RideStatus::InProgress,
                ]));
            })
            ->get();

        $results = $eligible->map(fn (Driver $driver) => [
            'driver_id' => $driver->id,
            'user_id' => $driver->user_id,
            'distance_km' => $distanceMap[$driver->id] ?? 999,
        ])->sortBy('distance_km')->values()->all();

        return $results;
    }

    public function markDriverRejected(Ride $ride, string $driverUserId): void
    {
        $key = sprintf(self::REJECTED_DRIVERS_KEY, $ride->id);
        $rejected = Cache::get($key, []);
        $rejected[] = $driverUserId;
        Cache::put($key, array_unique($rejected), config('matching.matching_timeout', 180));
    }

    public function setDispatchedDriver(Ride $ride, string $driverUserId): void
    {
        $key = sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id);
        Cache::put($key, $driverUserId, config('matching.driver_response_timeout', 30) + 5);
    }

    public function clearDispatchedDriver(Ride $ride): void
    {
        Cache::forget(sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id));
    }

    public function getDispatchedDriverId(Ride $ride): ?string
    {
        return Cache::get(sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id));
    }

    /**
     * @return list<string>
     */
    public function getRejectedDriverIds(Ride $ride): array
    {
        return Cache::get(sprintf(self::REJECTED_DRIVERS_KEY, $ride->id), []);
    }

    public function expandRadius(Ride $ride): void
    {
        $key = sprintf(self::RADIUS_STEP_KEY, $ride->id);
        $current = Cache::get($key, 0);
        Cache::put($key, $current + 1, config('matching.matching_timeout', 180));
    }

    public function cleanupRideCache(Ride $ride): void
    {
        Cache::forget(sprintf(self::REJECTED_DRIVERS_KEY, $ride->id));
        Cache::forget(sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id));
        Cache::forget(sprintf(self::RADIUS_STEP_KEY, $ride->id));
    }

    public function calculateCurrentRadius(Ride $ride): float
    {
        $step = Cache::get(sprintf(self::RADIUS_STEP_KEY, $ride->id), 0);

        $initial = config('matching.initial_radius_km', 3.0);
        $stepSize = config('matching.radius_step_km', 2.0);
        $max = config('matching.max_radius_km', 15.0);

        return min($initial + ($step * $stepSize), $max);
    }

    public function hasReachedMaxRadius(Ride $ride): bool
    {
        return $this->calculateCurrentRadius($ride) >= config('matching.max_radius_km', 15.0);
    }
}
