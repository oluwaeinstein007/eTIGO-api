<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Models\City;

class CityDetectionService
{
    public function __construct(
        private MapsGateway $mapsGateway,
    ) {}

    /**
     * @return array{city: City|null, resolved_address: string|null}
     */
    public function detectCity(float $lat, float $lng): array
    {
        $resolvedAddress = null;
        $city = null;

        try {
            $location = $this->mapsGateway->reverseGeocode($lat, $lng);
            $resolvedAddress = $location['address'];

            $cityName = $location['city'] ?? $this->extractCityName($resolvedAddress);

            $city = City::where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($cityName)])
                ->first();
        } catch (\Throwable) {
            // Reverse geocoding unavailable — fall through to boundary matching
        }

        if (! $city) {
            $city = $this->findCityByBoundary($lat, $lng);
        }

        return [
            'city' => $city,
            'resolved_address' => $resolvedAddress,
        ];
    }

    private function extractCityName(string $formattedAddress): string
    {
        $parts = array_map('trim', explode(',', $formattedAddress));

        return count($parts) >= 2 ? $parts[count($parts) - 2] : ($parts[0] ?? '');
    }

    private function findCityByBoundary(float $lat, float $lng): ?City
    {
        $candidates = [];

        $cities = City::where('is_active', true)
            ->whereNotNull('boundary')
            ->get();

        foreach ($cities as $city) {
            $boundary = $city->boundary;
            if (! $boundary) {
                continue;
            }

            $type = $boundary['type'] ?? '';

            if ($type === 'Point') {
                $centerLng = $boundary['coordinates'][0] ?? 0;
                $centerLat = $boundary['coordinates'][1] ?? 0;
                $radiusKm = $boundary['radius_km'] ?? 30;

                if ($this->haversineDistance($lat, $lng, $centerLat, $centerLng) <= $radiusKm) {
                    $area = M_PI * $radiusKm * $radiusKm;
                    $candidates[] = ['city' => $city, 'area' => $area];
                }
            } elseif ($type === 'Polygon') {
                $coordinates = $boundary['coordinates'] ?? [];
                if (! empty($coordinates) && $this->pointInPolygon($lat, $lng, $coordinates[0])) {
                    $area = $city->area_sq_km ?? City::calculateAreaFromPolygon($coordinates);
                    $candidates[] = ['city' => $city, 'area' => $area];
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        // When overlapping boundaries match, return the smallest (most specific) city
        usort($candidates, fn ($a, $b) => $a['area'] <=> $b['area']);

        return $candidates[0]['city'];
    }

    /**
     * Ray-casting algorithm for point-in-polygon detection.
     *
     * @param  array<int, array{0: float, 1: float}>  $polygon  GeoJSON ring: [[lng, lat], ...]
     */
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);
        $inside = false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][1]; // lat
            $yi = $polygon[$i][0]; // lng
            $xj = $polygon[$j][1];
            $yj = $polygon[$j][0];

            if (($yi > $lng) !== ($yj > $lng)
                && $lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    public function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
