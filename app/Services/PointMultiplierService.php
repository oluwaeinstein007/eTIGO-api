<?php

namespace App\Services;

use App\Enums\MultiplierConditionType;
use App\Models\PointMultiplierConfig;
use App\Models\Ride;
use App\Models\TripCarbonScore;
use Illuminate\Support\Carbon;

class PointMultiplierService
{
    /**
     * @return array{multiplier: float, reason: ?string, final_points: int}
     */
    public function applyMultipliers(TripCarbonScore $tripScore, Ride $ride): array
    {
        $matchedConditions = $this->evaluateConditions($ride);

        if (empty($matchedConditions)) {
            return [
                'multiplier' => 1.0,
                'reason' => null,
                'final_points' => $tripScore->base_points,
            ];
        }

        $totalMultiplier = $this->calculateStackedMultiplier($matchedConditions);
        $finalPoints = max(1, (int) round($tripScore->base_points * $totalMultiplier));

        $reasons = array_map(fn (array $c) => $c['type']->label(), $matchedConditions);
        $reason = implode(' + ', $reasons);

        $tripScore->update([
            'multiplier_applied' => round($totalMultiplier, 2),
            'multiplier_reason' => $reason,
            'final_points' => $finalPoints,
        ]);

        return [
            'multiplier' => round($totalMultiplier, 2),
            'reason' => $reason,
            'final_points' => $finalPoints,
        ];
    }

    /**
     * @return array<int, array{type: MultiplierConditionType, config: PointMultiplierConfig}>
     */
    private function evaluateConditions(Ride $ride): array
    {
        $matched = [];

        if ($this->isEvRide($ride)) {
            $config = PointMultiplierConfig::forCondition(MultiplierConditionType::EvRide);
            if ($config) {
                $matched[] = ['type' => MultiplierConditionType::EvRide, 'config' => $config];
            }
        }

        if ($this->isOffPeak($ride)) {
            $config = PointMultiplierConfig::forCondition(MultiplierConditionType::OffPeak);
            if ($config) {
                $matched[] = ['type' => MultiplierConditionType::OffPeak, 'config' => $config];
            }
        }

        return $matched;
    }

    /**
     * @param  array<int, array{type: MultiplierConditionType, config: PointMultiplierConfig}>  $conditions
     */
    private function calculateStackedMultiplier(array $conditions): float
    {
        $baseMultiplier = 1.0;
        $additionalMultiplier = 0.0;
        $hasNonStackable = false;

        foreach ($conditions as $condition) {
            $config = $condition['config'];

            if ($config->is_stackable) {
                $additionalMultiplier += ((float) $config->multiplier_value - 1.0);
            } else {
                if (! $hasNonStackable || (float) $config->multiplier_value > $baseMultiplier) {
                    $baseMultiplier = (float) $config->multiplier_value;
                    $hasNonStackable = true;
                }
            }
        }

        return $baseMultiplier + $additionalMultiplier;
    }

    private function isEvRide(Ride $ride): bool
    {
        $vehicleClass = $ride->vehicleClass;

        if (! $vehicleClass) {
            return false;
        }

        return CarbonScoreService::resolveVehicleEmission($vehicleClass) <= 0;
    }

    private function isOffPeak(Ride $ride): bool
    {
        $rideTime = $ride->started_at ?? $ride->created_at;

        if (! $rideTime instanceof Carbon) {
            $rideTime = Carbon::parse($rideTime);
        }

        $offPeakStart = config('gamification.off_peak_start', '22:00');
        $offPeakEnd = config('gamification.off_peak_end', '06:00');

        $hour = $rideTime->format('H:i');

        if ($offPeakStart > $offPeakEnd) {
            return $hour >= $offPeakStart || $hour < $offPeakEnd;
        }

        return $hour >= $offPeakStart && $hour < $offPeakEnd;
    }
}
