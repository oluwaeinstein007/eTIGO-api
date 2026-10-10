<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Models\Driver;
use App\Models\Ride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DriverMatchingService
{
    private const REJECTED_DRIVERS_KEY = 'ride:%s:rejected_drivers';

    private const DISPATCHED_DRIVER_KEY = 'ride:%s:dispatched_to';

    private const RADIUS_STEP_KEY = 'ride:%s:radius_step';

    private const AUTO_RETRY_KEY = 'ride:%s:auto_retry_count';

    public function __construct(
        private DriverLocationService $locationService,
    ) {}

    /**
     * @return array<int, array{driver_id: string, user_id: string, distance_km: float}>
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

        $nearby = array_values(array_filter(
            $nearby,
            fn (array $driver): bool => Str::isUuid($driver['driver_id']),
        ));

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
        Cache::put($key, array_unique($rejected), config('matching.matching_timeout', 300));
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
        Cache::put($key, $current + 1, config('matching.matching_timeout', 300));
    }

    public function cleanupRideCache(Ride $ride): void
    {
        Cache::forget(sprintf(self::REJECTED_DRIVERS_KEY, $ride->id));
        Cache::forget(sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id));
        Cache::forget(sprintf(self::RADIUS_STEP_KEY, $ride->id));
        Cache::forget(sprintf(self::AUTO_RETRY_KEY, $ride->id));
    }

    public function calculateCurrentRadius(Ride $ride): float
    {
        $step = (int) Cache::get(sprintf(self::RADIUS_STEP_KEY, $ride->id), 0);
        $tiers = config('matching.radius_tiers_km', [3.0, 7.0, 15.0]);

        return (float) $tiers[min($step, count($tiers) - 1)];
    }

    public function hasReachedMaxRadius(Ride $ride): bool
    {
        $step = (int) Cache::get(sprintf(self::RADIUS_STEP_KEY, $ride->id), 0);
        $tiers = config('matching.radius_tiers_km', [3.0, 7.0, 15.0]);

        return $step >= (count($tiers) - 1);
    }

    public function getAutoRetryCount(Ride $ride): int
    {
        return (int) Cache::get(sprintf(self::AUTO_RETRY_KEY, $ride->id), 0);
    }

    public function canAutoRetry(Ride $ride): bool
    {
        $maxRetries = (int) config('matching.max_auto_retries', 1);

        return $this->getAutoRetryCount($ride) < $maxRetries;
    }

    public function resetForAutoRetry(Ride $ride): void
    {
        $currentRetries = $this->getAutoRetryCount($ride);
        $timeout = config('matching.matching_timeout', 300);

        Cache::put(sprintf(self::AUTO_RETRY_KEY, $ride->id), $currentRetries + 1, $timeout);
        Cache::forget(sprintf(self::RADIUS_STEP_KEY, $ride->id));
        Cache::forget(sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id));
        Cache::forget(sprintf(self::REJECTED_DRIVERS_KEY, $ride->id));
    }
}
