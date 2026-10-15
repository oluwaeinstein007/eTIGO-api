<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Models\Driver;
use App\Models\Ride;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DriverMatchingService
{
    private const REJECTED_DRIVERS_KEY = 'ride:%s:rejected_drivers';

    private const DISPATCHED_DRIVER_KEY = 'ride:%s:dispatched_to';

    private const RADIUS_STEP_KEY = 'ride:%s:radius_step';

    private const AUTO_RETRY_KEY = 'ride:%s:auto_retry_count';

    private const DRIVER_OFFER_KEY = 'driver:%s:offered_ride';

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

        $eligible = Driver::query()
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
            ->get()
            ->reject(fn (Driver $driver) => $this->hasPendingOfferForAnotherRide($driver->user_id, $ride));

        if (! $this->isPriorityRide($ride)) {
            $held = $this->driversHeldForWaitingPriorityRides($ride, $eligible);
            $eligible = $eligible->reject(fn (Driver $driver) => in_array($driver->id, $held, true));
        }

        $results = $eligible->map(fn (Driver $driver) => [
            'driver_id' => $driver->id,
            'user_id' => $driver->user_id,
            'distance_km' => $distanceMap[$driver->id] ?? 999,
        ])->sortBy('distance_km')->values()->all();

        return $results;
    }

    /**
     * Gold, Platinum and Diamond passengers get priority matching, locked in
     * at booking.
     */
    public function isPriorityRide(Ride $ride): bool
    {
        return (bool) data_get($ride->pricing_snapshot, 'priority_matching', false);
    }

    /**
     * Priority matching never sends a passenger a farther driver: every ride
     * is offered its nearest eligible driver. What priority decides is who
     * gets a scarce driver: a free driver within the current search radius of
     * a priority ride that is waiting for its next search (between radius
     * tiers or retry waves) is kept for that ride instead of being offered to
     * a standard ride.
     *
     * @param  Collection<int, Driver>  $candidates
     * @return list<string> Ids of the drivers held for a priority ride
     */
    private function driversHeldForWaitingPriorityRides(Ride $ride, Collection $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        $waiting = Ride::query()
            ->where('status', RideStatus::Searching)
            ->whereKeyNot($ride->id)
            ->where('city_id', $ride->city_id)
            ->where('vehicle_class_id', $ride->vehicle_class_id)
            ->whereRaw("(pricing_snapshot->>'priority_matching')::boolean")
            ->get()
            ->filter(fn (Ride $priority) => $this->getDispatchedDriverId($priority) === null);

        if ($waiting->isEmpty()) {
            return [];
        }

        $held = [];

        foreach ($candidates as $driver) {
            $location = $this->locationService->getDriverLocation((string) $driver->id);

            if ($location === null) {
                continue;
            }

            foreach ($waiting as $priority) {
                $withinReach = $this->distanceKm($location['lat'], $location['lng'], (float) $priority->pickup_lat, (float) $priority->pickup_lng)
                    <= $this->calculateCurrentRadius($priority);

                if ($withinReach && ! in_array($driver->user_id, $this->getRejectedDriverIds($priority), true)) {
                    $held[] = $driver->id;

                    break;
                }
            }
        }

        return $held;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
        // The response-timeout job clears these keys explicitly; the TTL is only
        // a safety net and must outlive queue lag so the job still sees them.
        $ttl = (int) config('matching.driver_response_timeout', 20) + 60;

        Cache::put(sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id), $driverUserId, $ttl);
        Cache::put(sprintf(self::DRIVER_OFFER_KEY, $driverUserId), (string) $ride->id, $ttl);
    }

    public function clearDispatchedDriver(Ride $ride): void
    {
        $key = sprintf(self::DISPATCHED_DRIVER_KEY, $ride->id);
        $driverUserId = Cache::get($key);

        Cache::forget($key);

        if ($driverUserId) {
            $this->releaseDriverOffer($driverUserId, $ride);
        }
    }

    /**
     * Whether the driver is currently holding an unanswered offer for a
     * different ride, so they are never offered two rides at once.
     */
    public function hasPendingOfferForAnotherRide(string $driverUserId, Ride $ride): bool
    {
        $offeredRideId = Cache::get(sprintf(self::DRIVER_OFFER_KEY, $driverUserId));

        return $offeredRideId !== null && $offeredRideId !== (string) $ride->id;
    }

    private function releaseDriverOffer(string $driverUserId, Ride $ride): void
    {
        $offerKey = sprintf(self::DRIVER_OFFER_KEY, $driverUserId);

        if (Cache::get($offerKey) === (string) $ride->id) {
            Cache::forget($offerKey);
        }
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
        $this->clearDispatchedDriver($ride);
        Cache::forget(sprintf(self::REJECTED_DRIVERS_KEY, $ride->id));
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
        // Priority matching: Gold+ searches get extra automatic retry waves.
        $maxRetries = (int) config('matching.max_auto_retries', 1)
            + ($this->isPriorityRide($ride) ? (int) config('matching.priority_extra_auto_retries', 1) : 0);

        return $this->getAutoRetryCount($ride) < $maxRetries;
    }

    public function resetForAutoRetry(Ride $ride): void
    {
        $currentRetries = $this->getAutoRetryCount($ride);
        $timeout = config('matching.matching_timeout', 300);

        Cache::put(sprintf(self::AUTO_RETRY_KEY, $ride->id), $currentRetries + 1, $timeout);
        Cache::forget(sprintf(self::RADIUS_STEP_KEY, $ride->id));
        $this->clearDispatchedDriver($ride);
        Cache::forget(sprintf(self::REJECTED_DRIVERS_KEY, $ride->id));
    }
}
