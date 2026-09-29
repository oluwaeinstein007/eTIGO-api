<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Models\PricingConfig;

class FareEstimationService
{
    public function __construct(
        private MapsGateway $mapsGateway,
    ) {}

    /**
     * @return array{fare_estimate: string, distance_km: float, duration_minutes: float, currency: string, pricing_snapshot: array, waiting_time_policy: array}
     */
    public function estimate(
        PricingConfig $pricing,
        float $pickupLat,
        float $pickupLng,
        float $destinationLat,
        float $destinationLng,
        string $currency,
    ): array {
        $route = $this->mapsGateway->getDistanceAndDuration(
            $pickupLat, $pickupLng, $destinationLat, $destinationLng,
        );

        $fare = $this->calculateFare($pricing, $route['distance_km'], $route['duration_minutes']);

        return [
            'fare_estimate' => number_format($fare, 2, '.', ''),
            'distance_km' => $route['distance_km'],
            'duration_minutes' => $route['duration_minutes'],
            'currency' => $currency,
            'pricing_snapshot' => $pricing->toSnapshot(),
            'waiting_time_policy' => [
                'free_minutes' => $pricing->free_waiting_minutes,
                'per_minute_rate' => $pricing->waiting_time_rate,
            ],
        ];
    }

    public function calculateFare(PricingConfig $pricing, float $distanceKm, float $durationMinutes, float $waitingMinutes = 0): float
    {
        $distanceCharge = $distanceKm * (float) $pricing->per_km_rate;
        $timeCharge = $durationMinutes * (float) $pricing->per_minute_rate;
        $waitingCharge = $this->calculateWaitingCharge($pricing, $waitingMinutes);
        $calculatedFare = (float) $pricing->base_fare + $distanceCharge + $timeCharge + $waitingCharge;

        return max((float) $pricing->minimum_fare, round($calculatedFare, 2));
    }

    public function calculateWaitingCharge(PricingConfig $pricing, float $waitingMinutes): float
    {
        if ($waitingMinutes <= 0 || ! $pricing->waiting_time_rate) {
            return 0;
        }

        $chargeableMinutes = max(0, $waitingMinutes - $pricing->free_waiting_minutes);

        return round($chargeableMinutes * (float) $pricing->waiting_time_rate, 2);
    }

    /**
     * @return array<int, array{vehicle_class: array, fare_estimate: string, distance_km: float, duration_minutes: float, currency: string, waiting_time_policy: array}>
     */
    public function estimateAllClasses(
        int $cityId,
        float $pickupLat,
        float $pickupLng,
        float $destinationLat,
        float $destinationLng,
    ): array {
        $city = \App\Models\City::findOrFail($cityId);
        $currency = $city->currency_code;

        $activeClassIds = $city->vehicleClasses()
            ->wherePivot('is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->pluck('vehicle_classes.id');

        $estimates = [];

        foreach ($activeClassIds as $classId) {
            $pricing = PricingConfig::currentFor($cityId, $classId);

            if (! $pricing) {
                continue;
            }

            $vehicleClass = $pricing->vehicleClass;
            $result = $this->estimate($pricing, $pickupLat, $pickupLng, $destinationLat, $destinationLng, $currency);

            $estimates[] = [
                'vehicle_class' => [
                    'id' => $vehicleClass->id,
                    'name' => $vehicleClass->name,
                    'display_name' => $vehicleClass->display_name,
                    'capacity' => $vehicleClass->capacity,
                    'icon_url' => $vehicleClass->icon_url,
                ],
                'fare_estimate' => $result['fare_estimate'],
                'distance_km' => $result['distance_km'],
                'duration_minutes' => $result['duration_minutes'],
                'currency' => $result['currency'],
                'waiting_time_policy' => $result['waiting_time_policy'],
            ];
        }

        return $estimates;
    }
}
