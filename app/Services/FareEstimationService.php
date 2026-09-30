<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Models\City;
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
        $city = City::findOrFail($cityId);
        $currency = $city->currency_code;

        $activeClasses = $city->vehicleClasses()
            ->wherePivot('is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->orderBy('city_vehicle_classes.sort_order')
            ->orderBy('vehicle_classes.name')
            ->get();

        if ($activeClasses->isEmpty()) {
            return [];
        }

        $route = $this->mapsGateway->getDistanceAndDuration(
            $pickupLat, $pickupLng, $destinationLat, $destinationLng,
        );

        $pickupAddress = $this->mapsGateway->reverseGeocode($pickupLat, $pickupLng);
        $destinationAddress = $this->mapsGateway->reverseGeocode($destinationLat, $destinationLng);

        $estimates = [];

        foreach ($activeClasses as $vehicleClass) {
            $pricing = PricingConfig::currentFor($cityId, $vehicleClass->id);

            if (! $pricing) {
                continue;
            }

            $fare = $this->calculateFare($pricing, $route['distance_km'], $route['duration_minutes']);

            $estimates[] = [
                'vehicle_class' => [
                    'id' => $vehicleClass->id,
                    'name' => $vehicleClass->name,
                    'display_name' => $vehicleClass->display_name,
                    'capacity' => $vehicleClass->capacity,
                    'icon_url' => $vehicleClass->icon_url,
                ],
                'fare_estimate' => number_format($fare, 2, '.', ''),
                'distance_km' => $route['distance_km'],
                'duration_minutes' => $route['duration_minutes'],
                'currency' => $currency,
                'pickup_address' => $pickupAddress['address'],
                'destination_address' => $destinationAddress['address'],
                'pricing_snapshot' => $pricing->toSnapshot(),
                'waiting_time_policy' => [
                    'free_minutes' => $pricing->free_waiting_minutes,
                    'per_minute_rate' => $pricing->waiting_time_rate,
                ],
            ];
        }

        return $estimates;
    }
}
