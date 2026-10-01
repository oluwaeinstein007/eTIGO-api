<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Models\City;
use App\Models\PricingConfig;
use App\Models\SurgeRule;

class FareEstimationService
{
    public function __construct(
        private MapsGateway $mapsGateway,
        private SurgePricingService $surgePricingService,
        private CityDetectionService $cityDetectionService,
    ) {}

    /**
     * @return array{fare_estimate: string, distance_km: float, duration_minutes: float, currency: string, pricing_snapshot: array, waiting_time_policy: array, surge: array}
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

        $baseFare = $this->calculateFare($pricing, $route['distance_km'], $route['duration_minutes']);
        $surge = $this->surgePricingService->getCurrentMultiplier($pricing->city_id, $pricing->vehicle_class_id);
        $finalFare = $this->surgePricingService->applySurge($baseFare, $surge['multiplier']);

        return [
            'fare_estimate' => number_format($finalFare, 2, '.', ''),
            'distance_km' => $route['distance_km'],
            'duration_minutes' => $route['duration_minutes'],
            'currency' => $currency,
            'pricing_snapshot' => $pricing->toSnapshot(),
            'waiting_time_policy' => [
                'free_minutes' => $pricing->free_waiting_minutes,
                'per_minute_rate' => $pricing->waiting_time_rate,
            ],
            'surge' => $this->buildSurgeResponse($surge),
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
     * @return array{estimates: array, warnings: array<string>, cross_city: array|null}
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
            return ['estimates' => [], 'warnings' => [], 'cross_city' => null];
        }

        $route = $this->mapsGateway->getDistanceAndDuration(
            $pickupLat, $pickupLng, $destinationLat, $destinationLng,
        );

        try {
            $pickupAddress = $this->mapsGateway->reverseGeocode($pickupLat, $pickupLng);
        } catch (\Throwable) {
            $pickupAddress = ['address' => "{$pickupLat},{$pickupLng}"];
        }

        try {
            $destinationAddress = $this->mapsGateway->reverseGeocode($destinationLat, $destinationLng);
        } catch (\Throwable) {
            $destinationAddress = ['address' => "{$destinationLat},{$destinationLng}"];
        }

        $warnings = [];
        $crossCity = $this->detectCrossCity($cityId, $destinationLat, $destinationLng);

        if ($crossCity !== null) {
            $warnings[] = $crossCity['warning'];
        }

        if ($route['distance_km'] > 100) {
            $warnings[] = 'This is a long-distance ride (over 100 km). Fare is estimated based on route distance and may vary.';
        }

        $vehicleClassIds = $activeClasses->pluck('id')->all();
        $surgeMap = $this->surgePricingService->getMultipliersForCity($cityId, $vehicleClassIds);

        $estimates = [];

        foreach ($activeClasses as $vehicleClass) {
            $pricing = PricingConfig::currentFor($cityId, $vehicleClass->id);

            if (! $pricing) {
                continue;
            }

            $baseFare = $this->calculateFare($pricing, $route['distance_km'], $route['duration_minutes']);
            $surge = $surgeMap[$vehicleClass->id] ?? ['multiplier' => 1.0, 'rule' => null];
            $finalFare = $this->surgePricingService->applySurge($baseFare, $surge['multiplier']);

            $estimates[] = [
                'vehicle_class' => [
                    'id' => $vehicleClass->id,
                    'name' => $vehicleClass->name,
                    'display_name' => $vehicleClass->display_name,
                    'capacity' => $vehicleClass->capacity,
                    'icon_url' => $vehicleClass->icon_url,
                ],
                'fare_estimate' => number_format($finalFare, 2, '.', ''),
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
                'surge' => $this->buildSurgeResponse($surge),
            ];
        }

        return [
            'estimates' => $estimates,
            'warnings' => $warnings,
            'cross_city' => $crossCity,
        ];
    }

    /**
     * @return array{destination_city_id: int|null, destination_city_name: string|null, pickup_city_name: string, warning: string}|null
     */
    private function detectCrossCity(int $pickupCityId, float $destinationLat, float $destinationLng): ?array
    {
        $pickupCity = City::find($pickupCityId);

        if (! $pickupCity) {
            return null;
        }

        try {
            $detection = $this->cityDetectionService->detectCity($destinationLat, $destinationLng);
        } catch (\Throwable) {
            return null;
        }

        $destinationCity = $detection['city'];

        if ($destinationCity === null) {
            return [
                'destination_city_id' => null,
                'destination_city_name' => null,
                'pickup_city_name' => $pickupCity->name,
                'warning' => 'Your destination is outside our current service areas. Pickup city pricing applies. The driver may not be able to accept return trips from the destination.',
            ];
        }

        if ($destinationCity->id !== $pickupCityId) {
            return [
                'destination_city_id' => $destinationCity->id,
                'destination_city_name' => $destinationCity->name,
                'pickup_city_name' => $pickupCity->name,
                'warning' => "This is a cross-city ride from {$pickupCity->name} to {$destinationCity->name}. Pickup city ({$pickupCity->name}) pricing applies for this trip.",
            ];
        }

        return null;
    }

    /**
     * @param  array{multiplier: float, rule: SurgeRule|null}  $surge
     * @return array{active: bool, multiplier: float, rule_name: string|null}
     */
    private function buildSurgeResponse(array $surge): array
    {
        return [
            'active' => $surge['multiplier'] > 1.0,
            'multiplier' => $surge['multiplier'],
            'rule_name' => $surge['rule']?->name,
        ];
    }
}
