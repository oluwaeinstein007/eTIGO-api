<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Enums\CityLaunchStatus;
use App\Models\City;
use Illuminate\Support\Collection;

class CityDetectionService
{
    public function __construct(
        private MapsGateway $mapsGateway,
    ) {}

    /**
     * The city at a point. Only live cities by default; pass more launch
     * statuses to also find a city that is not open yet.
     *
     * The drawn boundary decides, as it does for bookings (pickupCity), so
     * the city the app is told is the one a booking from that point gets.
     * The reverse-geocoded name is only matched against cities with no
     * boundary yet: geocoders name districts ("Ikeja") that are not our
     * service areas. The address is returned for display either way.
     *
     * @param  list<CityLaunchStatus>  $launchStatuses
     * @return array{city: City|null, resolved_address: string|null}
     */
    public function detectCity(float $lat, float $lng, array $launchStatuses = [CityLaunchStatus::Live]): array
    {
        $resolvedAddress = null;
        $cityName = null;

        try {
            $location = $this->mapsGateway->reverseGeocode($lat, $lng);
            $resolvedAddress = $location['address'];
            $cityName = $location['city'] ?? $this->extractCityName($resolvedAddress);
        } catch (\Throwable) {
            // Reverse geocoding unavailable: the boundary still decides.
        }

        $city = $this->citiesAt($lat, $lng, $launchStatuses)->first();

        if (! $city && filled($cityName)) {
            $city = City::whereIn('launch_status', $launchStatuses)
                ->whereNull('boundary')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($cityName)])
                ->first();
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

    /**
     * The city a ride from this pickup belongs to, decided by the server
     * from the drawn boundaries (any launch status, so a city that has not
     * opened cannot be booked under a live city's name and prices). The
     * city the app sent is kept when the pickup lies inside it (a point on
     * a shared border is in both) or outside every drawn city; otherwise
     * the city the pickup is really in wins. Null only when the pickup is
     * outside every city and the app sent none. Boundaries only: no
     * reverse geocoding call.
     */
    public function pickupCity(?string $requestedCityId, float $lat, float $lng): ?City
    {
        $cities = $this->citiesAt($lat, $lng, CityLaunchStatus::cases());

        if ($requestedCityId !== null && ($cities->isEmpty() || $cities->contains('id', $requestedCityId))) {
            return City::find($requestedCityId);
        }

        return $cities->first();
    }

    /**
     * Cities whose drawn boundary contains the point, smallest first.
     *
     * @param  list<CityLaunchStatus>  $launchStatuses
     * @return Collection<int, City>
     */
    private function citiesAt(float $lat, float $lng, array $launchStatuses): Collection
    {
        $candidates = [];

        $cities = City::whereIn('launch_status', $launchStatuses)
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

        // When overlapping boundaries match, the smallest (most specific) city first
        usort($candidates, fn ($a, $b) => $a['area'] <=> $b['area']);

        return collect($candidates)->pluck('city');
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
