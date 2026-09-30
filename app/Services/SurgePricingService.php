<?php

namespace App\Services;

use App\Enums\SurgeType;
use App\Models\SurgeRule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SurgePricingService
{
    /**
     * @return array{multiplier: float, rule: SurgeRule|null}
     */
    public function getCurrentMultiplier(int $cityId, ?int $vehicleClassId = null): array
    {
        $rules = SurgeRule::active()
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
                SurgeType::TimeBased => $this->matchesTimeSchedule($rule->conditions),
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

    private function matchesTimeSchedule(array $conditions): bool
    {
        $now = Carbon::now();
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

    private function matchesDemandThreshold(SurgeRule $rule): bool
    {
        $minRatio = $rule->conditions['min_demand_supply_ratio'] ?? null;

        if (! $minRatio) {
            return false;
        }

        $currentRatio = $this->calculateDemandSupplyRatio($rule->city_id, $rule->vehicle_class_id);

        return $currentRatio >= $minRatio;
    }

    protected function calculateDemandSupplyRatio(int $cityId, ?int $vehicleClassId): float
    {
        // TODO: Implement with real ride request and driver availability data.
        // Return 0.0 so demand-based rules don't fire until wired to real metrics.
        return 0.0;
    }

    /**
     * @return array<int|null, array{multiplier: float, rule: SurgeRule|null}>
     */
    public function getMultipliersForCity(int $cityId, array $vehicleClassIds): array
    {
        $allRules = SurgeRule::active()
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
