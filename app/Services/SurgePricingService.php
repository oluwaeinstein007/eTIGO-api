<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Enums\SurgeType;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\SurgeRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SurgePricingService
{
    public function __construct(private ?DriverLocationService $locations = null) {}

    /**
     * @return array{multiplier: float, rule: SurgeRule|null}
     */
    public function getCurrentMultiplier(string $cityId, ?string $vehicleClassId = null): array
    {
        $rules = SurgeRule::with('city')
            ->active()
            ->forCity($cityId)
            ->forVehicleClass($vehicleClassId)
            ->orderByDesc('priority')
            ->get();

        if ($rules->isEmpty()) {
            return ['multiplier' => 1.0, 'rule' => null];
        }

        $matchingRule = $this->findMatchingRule($rules);

        if (! $matchingRule) {
            return ['multiplier' => 1.0, 'rule' => null];
        }

        return [
            'multiplier' => (float) $matchingRule->multiplier,
            'rule' => $matchingRule,
        ];
    }

    private function findMatchingRule(Collection $rules): ?SurgeRule
    {
        $bestRule = null;
        $bestPriority = -1;

        foreach ($rules as $rule) {
            if ($rule->priority < $bestPriority) {
                continue;
            }

            $matches = match ($rule->type) {
                SurgeType::Manual => true,
                SurgeType::TimeBased => $this->matchesTimeSchedule($rule->conditions, $rule->city?->timezone),
                SurgeType::DemandBased => $this->matchesDemandThreshold($rule),
            };

            if ($matches && $rule->priority >= $bestPriority) {
                if ($rule->priority > $bestPriority || ! $bestRule || (float) $rule->multiplier > (float) $bestRule->multiplier) {
                    $bestRule = $rule;
                    $bestPriority = $rule->priority;
                }
            }
        }

        return $bestRule;
    }

    private function matchesTimeSchedule(array $conditions, ?string $timezone = null): bool
    {
        $now = Carbon::now($timezone);
        $daysOfWeek = $conditions['days_of_week'] ?? [];
        $startTime = $conditions['start_time'] ?? null;
        $endTime = $conditions['end_time'] ?? null;

        if (empty($daysOfWeek) || ! $startTime || ! $endTime) {
            return false;
        }

        $currentTime = $now->format('H:i');

        $isOvernight = $startTime > $endTime;

        if ($isOvernight && $currentTime < $endTime) {
            $yesterday = $now->copy()->subDay()->dayOfWeekIso;

            return in_array($yesterday, $daysOfWeek);
        }

        if (! in_array($now->dayOfWeekIso, $daysOfWeek)) {
            return false;
        }

        if ($isOvernight) {
            return $currentTime >= $startTime;
        }

        return $currentTime >= $startTime && $currentTime < $endTime;
    }

    /**
     * Compares the exact demand/supply ratio, not the rounded one shown to
     * admins. When supply could not be measured the rule does not fire.
     */
    private function matchesDemandThreshold(SurgeRule $rule): bool
    {
        $minRatio = $rule->conditions['min_demand_supply_ratio'] ?? null;

        if (! $minRatio) {
            return false;
        }

        $demand = $this->demandSnapshot($rule->city_id, $rule->vehicle_class_id);
        $minRequests = (int) ($rule->conditions['min_requests'] ?? config('surge.demand_min_requests', 5));

        if ($demand['available_drivers'] === null || $demand['requests'] < $minRequests) {
            return false;
        }

        return self::exactRatio($demand['requests'], $demand['available_drivers']) >= (float) $minRatio;
    }

    /**
     * Current demand against supply for a city (and optionally one class):
     * passengers who asked for a ride within the demand window, and drivers
     * free to take one right now. With no free driver, every request is
     * unmet, so the ratio equals the number of requests. If driver locations
     * cannot be read, supply is unknown (`available_drivers` and `ratio` are
     * null) and the result is not cached.
     *
     * @return array{requests: int, available_drivers: int|null, ratio: float|null, window_minutes: int}
     */
    public function demandSnapshot(string $cityId, ?string $vehicleClassId = null): array
    {
        $key = sprintf('surge:demand:%s:%s', $cityId, $vehicleClassId ?? 'all');

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $window = (int) config('surge.demand_window_minutes', 10);

        $requests = Ride::query()
            ->where('city_id', $cityId)
            ->when($vehicleClassId, fn ($q) => $q->where('vehicle_class_id', $vehicleClassId))
            ->where('created_at', '>=', now()->subMinutes($window))
            ->distinct()
            ->count('passenger_id');

        $available = $this->countFreshlyLocated($this->idleDriverIds($cityId, $vehicleClassId));

        $snapshot = [
            'requests' => $requests,
            'available_drivers' => $available,
            'ratio' => $available === null ? null : round(self::exactRatio($requests, $available), 2),
            'window_minutes' => $window,
        ];

        if ($available !== null) {
            Cache::put($key, $snapshot, (int) config('surge.demand_cache_seconds', 60));
        }

        return $snapshot;
    }

    private static function exactRatio(int $requests, int $available): float
    {
        return $available > 0 ? $requests / $available : (float) $requests;
    }

    /**
     * @return Collection<int, string>
     */
    private function idleDriverIds(string $cityId, ?string $vehicleClassId): Collection
    {
        return Driver::query()
            ->where('city_id', $cityId)
            ->where('status', DriverStatus::Approved)
            ->where('is_online', true)
            ->whereHas('vehicle', fn ($q) => $q->when($vehicleClassId, fn ($v) => $v->where('vehicle_class_id', $vehicleClassId)))
            ->whereDoesntHave('user', fn ($q) => $q->where('email', 'like', '%@'.config('matching.demo_driver_email_domain')))
            ->whereDoesntHave('user', fn ($q) => $q->whereHas(
                'driverRides',
                fn ($r) => $r->whereIn('status', [RideStatus::Matched, RideStatus::DriverEnRoute, RideStatus::DriverArrived, RideStatus::InProgress]),
            ))
            ->pluck('id');
    }

    /**
     * Drivers whose app stopped reporting a location are not really available.
     * Returns null when the location store cannot be read: the online flag
     * alone is no proof that a driver is there.
     *
     * @param  Collection<int, string>  $driverIds
     */
    private function countFreshlyLocated(Collection $driverIds): ?int
    {
        $freshAfter = now()->subSeconds(DriverLocationService::STALE_LOCATION_SECONDS)->timestamp;

        try {
            $locations = $this->locations ?? app(DriverLocationService::class);

            return $driverIds
                ->filter(fn (string $id) => ($locations->getDriverLocation($id)['timestamp'] ?? 0) >= $freshAfter)
                ->count();
        } catch (\Throwable $e) {
            Log::warning('Surge supply unknown: driver locations could not be read', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<int|null, array{multiplier: float, rule: SurgeRule|null}>
     */
    public function getMultipliersForCity(string $cityId, array $vehicleClassIds): array
    {
        $allRules = SurgeRule::with('city')
            ->active()
            ->forCity($cityId)
            ->where(function ($q) use ($vehicleClassIds) {
                $q->whereNull('vehicle_class_id')
                    ->orWhereIn('vehicle_class_id', $vehicleClassIds);
            })
            ->orderByDesc('priority')
            ->get();

        $results = [];

        foreach ($vehicleClassIds as $vcId) {
            $applicable = $allRules->filter(
                fn (SurgeRule $r) => $r->vehicle_class_id === null || $r->vehicle_class_id === $vcId,
            );

            $matchingRule = $this->findMatchingRule($applicable);

            $results[$vcId] = [
                'multiplier' => $matchingRule ? (float) $matchingRule->multiplier : 1.0,
                'rule' => $matchingRule,
            ];
        }

        return $results;
    }

    public function applySurge(float $fare, float $multiplier): float
    {
        return round($fare * $multiplier, 2);
    }
}
