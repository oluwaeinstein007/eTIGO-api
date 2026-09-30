<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MapsGateway;
use App\Http\Controllers\Controller;
use App\Http\Resources\CityResource;
use App\Models\City;
use App\Services\AppCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function __construct(
        private AppCacheService $cache,
    ) {}

    public function index(): JsonResponse
    {
        $cities = $this->cache->activeCities();

        return response()->json([
            'cities' => CityResource::collection($cities),
        ]);
    }

    public function detect(Request $request, MapsGateway $mapsGateway): JsonResponse
    {
        $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');

        $location = $mapsGateway->reverseGeocode($lat, $lng);

        $city = City::where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($this->extractCityName($location['address']))])
            ->first();

        if (! $city) {
            $city = $this->findCityByBoundary($lat, $lng);
        }

        if (! $city) {
            return response()->json([
                'message' => 'No active city found for this location.',
                'resolved_address' => $location['address'],
            ], 404);
        }

        return response()->json([
            'city' => new CityResource($city),
            'resolved_address' => $location['address'],
        ]);
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

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
