<?php

namespace App\Services;

use App\Contracts\MapsGateway;

class HaversineMapsGateway implements MapsGateway
{
    public function getDistanceAndDuration(
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng,
    ): array {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($destinationLat - $originLat);
        $dLng = deg2rad($destinationLng - $originLng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($originLat)) * cos(deg2rad($destinationLat)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        $straightLineKm = $earthRadiusKm * $c;

        // Road distance is typically ~1.3x straight-line distance
        $distanceKm = round($straightLineKm * 1.3, 2);

        // Estimate duration assuming 30 km/h average urban speed
        $durationMinutes = round(($distanceKm / 30) * 60, 1);

        return [
            'distance_km' => $distanceKm,
            'duration_minutes' => $durationMinutes,
        ];
    }

    /**
     * @return array{lat: float, lng: float, formatted_address: string}
     */
    public function geocode(string $address): array
    {
        throw new \RuntimeException('Geocoding is not available with the Haversine fallback gateway. Configure Google Maps.');
    }

    /**
     * @return array{address: string, place_id: string, city: string|null}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        return [
            'address' => "{$lat},{$lng}",
            'place_id' => '',
            'city' => null,
        ];
    }
}
