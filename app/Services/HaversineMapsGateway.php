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
     * Without a routing provider the route is the straight line between the
     * two points.
     *
     * @return array{encoded_polyline: string, distance_km: float, duration_minutes: float}
     */
    public function getRoute(
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng,
    ): array {
        return [
            'encoded_polyline' => self::encodePolyline([
                [$originLat, $originLng],
                [$destinationLat, $destinationLng],
            ]),
            ...$this->getDistanceAndDuration($originLat, $originLng, $destinationLat, $destinationLng),
        ];
    }

    /**
     * Encode points with Google's polyline algorithm at precision 5.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function encodePolyline(array $points): string
    {
        $encoded = '';
        $previousLat = 0;
        $previousLng = 0;

        foreach ($points as [$lat, $lng]) {
            $latE5 = (int) round($lat * 1e5);
            $lngE5 = (int) round($lng * 1e5);

            foreach ([$latE5 - $previousLat, $lngE5 - $previousLng] as $delta) {
                $value = $delta < 0 ? ~($delta << 1) : $delta << 1;

                while ($value >= 0x20) {
                    $encoded .= chr((0x20 | ($value & 0x1F)) + 63);
                    $value >>= 5;
                }

                $encoded .= chr($value + 63);
            }

            $previousLat = $latE5;
            $previousLng = $lngE5;
        }

        return $encoded;
    }

    /**
     * @return array{lat: float, lng: float, formatted_address: string}
     */
    public function geocode(string $address): array
    {
        throw new \RuntimeException('Geocoding is not available with the Haversine fallback gateway. Configure Mapbox.');
    }

    /**
     * @return array{id: string, place_id: string, name: string, address: string, latitude: float, longitude: float, city: string|null}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        return [
            'id' => '',
            'place_id' => '',
            'name' => "{$lat},{$lng}",
            'address' => "{$lat},{$lng}",
            'latitude' => $lat,
            'longitude' => $lng,
            'city' => null,
        ];
    }

    /**
     * @return list<array{id: string, name: string, address: string, latitude: float, longitude: float}>
     */
    public function searchPlaces(
        string $query,
        ?float $proximityLat = null,
        ?float $proximityLng = null,
        int $limit = 10,
    ): array {
        return [];
    }
}
