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

            $city = City::where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($this->extractCityName($resolvedAddress))])
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
        return City::where('is_active', true)
            ->whereNotNull('boundary')
            ->get()
            ->first(function (City $city) use ($lat, $lng) {
                $boundary = $city->boundary;

                if (! $boundary || ($boundary['type'] ?? '') !== 'Point') {
                    return false;
                }

                $centerLng = $boundary['coordinates'][0] ?? 0;
                $centerLat = $boundary['coordinates'][1] ?? 0;
                $radiusKm = $boundary['radius_km'] ?? 30;

                return $this->haversineDistance($lat, $lng, $centerLat, $centerLng) <= $radiusKm;
            });
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
