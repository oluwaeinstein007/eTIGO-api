<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MapsGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PlaceController extends Controller
{
    public function __construct(
        private MapsGateway $mapsGateway,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'proximity_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'proximity_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $query = trim($validated['q']);
        $proximityLat = isset($validated['proximity_lat']) ? (float) $validated['proximity_lat'] : null;
        $proximityLng = isset($validated['proximity_lng']) ? (float) $validated['proximity_lng'] : null;
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 10;

        $latBucket = $proximityLat !== null ? round($proximityLat, 2) : 'null';
        $lngBucket = $proximityLng !== null ? round($proximityLng, 2) : 'null';
        $cacheKey = 'places_search:'.md5(mb_strtolower($query).":{$latBucket}:{$lngBucket}:{$limit}");

        $places = Cache::remember($cacheKey, now()->addHours(6), function () use ($query, $proximityLat, $proximityLng, $limit) {
            return $this->mapsGateway->searchPlaces(
                query: $query,
                proximityLat: $proximityLat,
                proximityLng: $proximityLng,
                limit: $limit,
            );
        });

        return response()->json([
            'places' => $places,
        ]);
    }

    public function reverse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $latBucket = round($lat, 4);
        $lngBucket = round($lng, 4);
        $cacheKey = "places_reverse:{$latBucket}:{$lngBucket}";

        $place = Cache::remember($cacheKey, now()->addDays(7), function () use ($lat, $lng) {
            return $this->mapsGateway->reverseGeocode($lat, $lng);
        });

        return response()->json([
            'place' => $place,
        ]);
    }
}
