<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PlaceSearchGateway;
use App\Http\Controllers\Controller;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PlaceController extends Controller
{
    public function __construct(
        private PlaceSearchGateway $placeSearchGateway,
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

        $providerPlaces = Cache::remember($cacheKey, now()->addHours(6), function () use ($query, $proximityLat, $proximityLng, $limit) {
            return $this->placeSearchGateway->searchPlaces(
                query: $query,
                proximityLat: $proximityLat,
                proximityLng: $proximityLng,
                limit: $limit,
            );
        });

        $providerPlaces = array_map(
            fn (array $place) => $place + ['source' => 'search'],
            $providerPlaces,
        );

        $savedPlaces = $this->matchingSavedPlaces($request->user(), $query);

        return response()->json([
            'places' => $this->mergePlaces($savedPlaces, $providerPlaces, $limit),
        ]);
    }

    /**
     * @return list<array{id: string, name: string, address: string, latitude: float, longitude: float, source: string, saved_place_type: string}>
     */
    private function matchingSavedPlaces(?User $user, string $query): array
    {
        if (! $user instanceof User || ! $user->isPassenger() || $query === '') {
            return [];
        }

        $pattern = '%'.addcslashes($query, '\\%_').'%';

        return $user->savedPlaces()
            ->where(function ($q) use ($pattern) {
                $q->whereLike('name', $pattern)
                    ->orWhereLike('address', $pattern);
            })
            ->orderByRaw("CASE type WHEN 'home' THEN 0 WHEN 'work' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn (SavedPlace $place) => [
                'id' => $place->id,
                'name' => $place->name,
                'address' => $place->address,
                'latitude' => (float) $place->latitude,
                'longitude' => (float) $place->longitude,
                'source' => 'saved_place',
                'saved_place_type' => $place->type,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $savedPlaces
     * @param  list<array<string, mixed>>  $providerPlaces
     * @return list<array<string, mixed>>
     */
    private function mergePlaces(array $savedPlaces, array $providerPlaces, int $limit): array
    {
        if ($savedPlaces === []) {
            return array_slice($providerPlaces, 0, $limit);
        }

        $savedCoordinates = array_map(
            fn (array $place) => $this->coordinateKey($place['latitude'], $place['longitude']),
            $savedPlaces,
        );

        $providerPlaces = array_values(array_filter(
            $providerPlaces,
            fn (array $place) => ! in_array($this->coordinateKey($place['latitude'], $place['longitude']), $savedCoordinates, true),
        ));

        return array_slice([...$savedPlaces, ...$providerPlaces], 0, $limit);
    }

    private function coordinateKey(float $latitude, float $longitude): string
    {
        return round($latitude, 4).':'.round($longitude, 4);
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
            return $this->placeSearchGateway->reverseGeocode($lat, $lng);
        });

        return response()->json([
            'place' => $place,
        ]);
    }
}
