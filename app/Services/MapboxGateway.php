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
     * @return array{address: string, place_id: string, city: string|null}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        $response = Http::get("https://api.mapbox.com/search/geocode/v6/reverse", [
            'longitude' => $lng,
            'latitude' => $lat,
            'access_token' => $this->accessToken,
            'limit' => 1,
        ]);

        $response->throw();

        $data = $response->json();

        if (empty($data['features'])) {
            throw new RuntimeException('Mapbox Reverse Geocoding API returned no results.');
        }

        $feature = $data['features'][0];
        $context = $feature['properties']['context'] ?? [];

        return [
            'address' => $feature['properties']['full_address'] ?? "{$lat},{$lng}",
            'place_id' => $feature['id'] ?? '',
            'city' => $context['place']['name'] ?? $context['locality']['name'] ?? null,
        ];
    }
}
