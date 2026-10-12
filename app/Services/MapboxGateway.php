<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MapboxGateway implements MapsGateway
{
    public function __construct(
        private string $accessToken,
    ) {}

    /**
     * @return array{distance_km: float, duration_minutes: float}
     */
    public function getDistanceAndDuration(
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng,
    ): array {
        $coordinates = "{$originLng},{$originLat};{$destinationLng},{$destinationLat}";

        $response = Http::get("https://api.mapbox.com/directions/v5/mapbox/driving/{$coordinates}", [
            'access_token' => $this->accessToken,
            'geometries' => 'geojson',
            'overview' => 'false',
            'exclude' => 'ferry',
        ]);

        $response->throw();

        $data = $response->json();

        if (empty($data['routes'])) {
            throw new RuntimeException('Mapbox Directions API returned no routes.');
        }

        $route = $data['routes'][0];

        return [
            'distance_km' => round($route['distance'] / 1000, 2),
            'duration_minutes' => round($route['duration'] / 60, 1),
        ];
    }

    /**
     * @return array{lat: float, lng: float, formatted_address: string}
     */
    public function geocode(string $address): array
    {
        $response = Http::get('https://api.mapbox.com/search/geocode/v6/forward', [
            'q' => $address,
            'access_token' => $this->accessToken,
            'limit' => 1,
        ]);

        $response->throw();

        $data = $response->json();

        if (empty($data['features'])) {
            throw new RuntimeException('Mapbox Geocoding API returned no results.');
        }

        $feature = $data['features'][0];
        $coordinates = $feature['geometry']['coordinates'];

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
            'formatted_address' => $feature['properties']['full_address'] ?? $feature['properties']['name'] ?? $address,
        ];
    }

    /**
     * @return array{id: string, place_id: string, name: string, address: string, latitude: float, longitude: float, city: string|null}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        $response = Http::get('https://api.mapbox.com/search/geocode/v6/reverse', [
            'longitude' => $lng,
            'latitude' => $lat,
            'access_token' => $this->accessToken,
        ]);

        $response->throw();

        $data = $response->json();

        if (empty($data['features'])) {
            throw new RuntimeException('Mapbox Reverse Geocoding API returned no results.');
        }

        $feature = $data['features'][0];
        $context = $feature['properties']['context'] ?? [];
        $name = $feature['properties']['name'] ?? '';
        $fullAddress = $feature['properties']['full_address'] ?? "{$lat},{$lng}";

        return [
            'id' => (string) ($feature['id'] ?? ''),
            'place_id' => (string) ($feature['id'] ?? ''),
            'name' => $name !== '' ? $name : $fullAddress,
            'address' => $fullAddress,
            'latitude' => $lat,
            'longitude' => $lng,
            'city' => $context['place']['name'] ?? $context['locality']['name'] ?? null,
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
        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return [];
        }

        $params = [
            'q' => $cleanQuery,
            'access_token' => $this->accessToken,
            'country' => 'ng',
            'types' => 'district,place,locality,neighborhood,address,street',
            'limit' => min(max($limit, 1), 20),
        ];

        if ($proximityLat !== null && $proximityLng !== null) {
            $params['proximity'] = "{$proximityLng},{$proximityLat}";
        } else {
            $params['proximity'] = '3.3792,6.5244';
        }

        $response = Http::get('https://api.mapbox.com/search/geocode/v6/forward', $params);

        $response->throw();

        $data = $response->json();
        $features = $data['features'] ?? [];

        $places = [];
        foreach ($features as $feature) {
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }

            $name = $feature['properties']['name'] ?? '';
            $fullAddress = $feature['properties']['full_address'] ?? $name;
            $address = $fullAddress;
            if ($name !== '' && $name !== $fullAddress && str_starts_with($fullAddress, "{$name}, ")) {
                $address = substr($fullAddress, strlen("{$name}, "));
            }

            $places[] = [
                'id' => (string) ($feature['id'] ?? ''),
                'name' => $name !== '' ? $name : $fullAddress,
                'address' => $address !== '' ? $address : $fullAddress,
                'latitude' => (float) $coordinates[1],
                'longitude' => (float) $coordinates[0],
            ];
        }

        return $places;
    }
}
