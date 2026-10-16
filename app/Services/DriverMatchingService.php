<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Models\AppSetting;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\RideStateTransition;
use Illuminate\Support\Carbon;
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
            ->whereDoesntHave('user', fn ($q) => $q->where('email', 'like', '%@'.config('matching.demo_driver_email_domain')))
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
     * gets a scarce driver: a priority ride waiting for its next search
     * (between radius tiers or retry waves) keeps the driver it would be
     * offered next, the nearest free driver within its current radius,
     * instead of that driver going to a standard ride. Every other driver
     * stays available to everyone.
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
            ->oldest()
            ->get()
            ->filter(fn (Ride $priority) => $this->getDispatchedDriverId($priority) === null);

        if ($waiting->isEmpty()) {
            return [];
        }

        $positions = $candidates
            ->mapWithKeys(fn (Driver $driver) => [$driver->id => $this->locationService->getDriverLocation((string) $driver->id)])
            ->filter();
        $userIds = $candidates->pluck('user_id', 'id');

        $held = [];

        // Earliest booking first, so two waiting priority rides never hold the same driver.
        foreach ($waiting as $priority) {
            $radius = $this->calculateCurrentRadius($priority);
            $rejected = $this->getRejectedDriverIds($priority);

            $next = $positions
                ->reject(fn (array $position, string $driverId) => in_array($driverId, $held, true)
                    || in_array($userIds[$driverId], $rejected, true))
                ->map(fn (array $position) => $this->distanceKm($position['lat'], $position['lng'], (float) $priority->pickup_lat, (float) $priority->pickup_lng))
                ->filter(fn (float $distance) => $distance <= $radius)
                ->sort()
                ->keys()
                ->first();

            if ($next !== null) {
                $held[] = $next;
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

    /**
     * When the current search gives up: matching_timeout after the search
     * began, or, for a scheduled ride the dispatcher started, the end of the
     * grace period after its pickup time if that is later.
     */
    public function searchDeadline(Ride $ride): Carbon
    {
        $searchStartedAt = RideStateTransition::query()
            ->where('ride_id', $ride->id)
            ->where('to_state', RideStatus::Searching->value)
            ->latest('created_at')
            ->value('created_at') ?? $ride->created_at;

        $deadline = Carbon::parse($searchStartedAt)->addSeconds((int) config('matching.matching_timeout', 300));
        $graceEnd = $this->scheduledGraceEnd($ride);

        return $graceEnd !== null && $graceEnd->gt($deadline) ? $graceEnd : $deadline;
    }

    /**
     * For a scheduled ride the dispatcher started: the pickup time plus the
     * matching grace period. Null for on-demand rides.
     */
    public function scheduledGraceEnd(Ride $ride): ?Carbon
    {
        $scheduled = $ride->scheduledRide;

        if ($scheduled === null || $scheduled->dispatched_at === null) {
            return null;
        }

        return $scheduled->scheduled_pickup_at->copy()
            ->addMinutes(AppSetting::scheduledRides()['matching_grace_minutes']);
    }

    /**
     * A scheduled ride keeps searching in waves until its grace period ends,
     * rather than stopping after the on-demand retry allowance.
     */
    private function isInScheduledSearchWindow(Ride $ride): bool
    {
        return $this->scheduledGraceEnd($ride)?->isFuture() ?? false;
    }

    public function autoRetryDelaySeconds(Ride $ride): int
    {
        return $this->isInScheduledSearchWindow($ride)
            ? AppSetting::scheduledRides()['retry_wave_delay_seconds']
            : (int) config('matching.auto_retry_delay_seconds', 10);
    }

    public function canAutoRetry(Ride $ride): bool
    {
        if ($this->isInScheduledSearchWindow($ride)) {
            return true;
        }

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

        // A scheduled ride runs many waves; a driver who declined is not
        // offered it again until their rejection expires (matching_timeout).
        if (! $this->isInScheduledSearchWindow($ride)) {
            Cache::forget(sprintf(self::REJECTED_DRIVERS_KEY, $ride->id));
        }
    }
}
