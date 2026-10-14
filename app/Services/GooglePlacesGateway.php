<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GooglePlacesGateway implements MapsGateway
{
    public function __construct(
        private string $apiKey,
        private ?string $country = 'ng',
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
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
        ])->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
            'origin' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $originLat,
                        'longitude' => $originLng,
                    ],
                ],
            ],
            'destination' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $destinationLat,
                        'longitude' => $destinationLng,
                    ],
                ],
            ],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_UNAWARE',
        ]);

        $response->throw();

        $data = $response->json();
        $routes = $data['routes'] ?? [];

        if (empty($routes)) {
            throw new RuntimeException('Google Routes API returned no routes.');
        }

        $route = $routes[0];
        $distanceMeters = (float) ($route['distanceMeters'] ?? 0);
        $durationSeconds = (float) rtrim((string) ($route['duration'] ?? '0s'), 's');

        return [
            'distance_km' => round($distanceMeters / 1000, 2),
            'duration_minutes' => round($durationSeconds / 60, 1),
        ];
    }

    /**
     * @return array{lat: float, lng: float, formatted_address: string}
     */
    public function geocode(string $address): array
    {
        $cleanAddress = trim($address);
        if ($cleanAddress === '') {
            throw new RuntimeException('Address cannot be empty.');
        }

        $payload = [
            'textQuery' => $cleanAddress,
            'pageSize' => 1,
        ];

        if ($this->country) {
            $payload['regionCode'] = strtoupper($this->country);
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'places.formattedAddress,places.location',
        ])->post('https://places.googleapis.com/v1/places:searchText', $payload);

        $response->throw();

        $data = $response->json();
        $places = $data['places'] ?? [];

        if (empty($places)) {
            throw new RuntimeException('Google Places API returned no geocoding results for address.');
        }

        $place = $places[0];
        $location = $place['location'] ?? null;
        if (! is_array($location) || ! isset($location['latitude'], $location['longitude'])) {
            throw new RuntimeException('Google Places API geocoding result missing coordinates.');
        }

        return [
            'lat' => (float) $location['latitude'],
            'lng' => (float) $location['longitude'],
            'formatted_address' => (string) ($place['formattedAddress'] ?? $cleanAddress),
        ];
    }

    /**
     * @return array{id: string, place_id: string, name: string, address: string, latitude: float, longitude: float, city: string|null}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        $payload = [
            'locationRestriction' => [
                'circle' => [
                    'center' => [
                        'latitude' => $lat,
                        'longitude' => $lng,
                    ],
                    'radius' => 500.0,
                ],
            ],
            'maxResultCount' => 1,
        ];

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.addressComponents',
        ])->post('https://places.googleapis.com/v1/places:searchNearby', $payload);

        $response->throw();

        $data = $response->json();
        $places = $data['places'] ?? [];

        if (empty($places)) {
            $payload['locationRestriction']['circle']['radius'] = 2500.0;
            $retry = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.addressComponents',
            ])->post('https://places.googleapis.com/v1/places:searchNearby', $payload);

            if ($retry->successful()) {
                $places = $retry->json('places') ?? [];
            }
        }

        if (empty($places)) {
            throw new RuntimeException('Google Places API returned no results for coordinates.');
        }

        $place = $places[0];
        $id = (string) ($place['id'] ?? '');
        $name = $place['displayName']['text'] ?? '';
        $fullAddress = $place['formattedAddress'] ?? "{$lat},{$lng}";

        $city = null;
        $components = $place['addressComponents'] ?? [];
        foreach ($components as $component) {
            $types = $component['types'] ?? [];
            if (in_array('locality', $types, true)) {
                $city = $component['longText'] ?? $component['shortText'] ?? null;
                break;
            }
        }
        if (! $city) {
            foreach ($components as $component) {
                $types = $component['types'] ?? [];
                if (in_array('administrative_area_level_2', $types, true)) {
                    $city = $component['longText'] ?? $component['shortText'] ?? null;
                    break;
                }
            }
        }
        if (! $city) {
            foreach ($components as $component) {
                $types = $component['types'] ?? [];
                if (in_array('neighborhood', $types, true)) {
                    $city = $component['longText'] ?? $component['shortText'] ?? null;
                    break;
                }
            }
        }

        $displayName = $name !== '' ? $name : $fullAddress;
        if ($city !== null && mb_strtolower(trim($displayName)) === mb_strtolower(trim($city)) && $fullAddress !== '') {
            $displayName = $fullAddress;
        }

        return [
            'id' => $id,
            'place_id' => $id,
            'name' => $displayName,
            'address' => $fullAddress,
            'latitude' => $lat,
            'longitude' => $lng,
            'city' => $city,
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

        $payload = [
            'textQuery' => $cleanQuery,
            'pageSize' => min(max($limit, 1), 20),
        ];

        if ($this->country) {
            $payload['regionCode'] = strtoupper($this->country);
        }

        if ($proximityLat !== null && $proximityLng !== null) {
            $payload['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => $proximityLat,
                        'longitude' => $proximityLng,
                    ],
                    'radius' => 50000.0,
                ],
            ];
        } else {
            $payload['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => 6.5244,
                        'longitude' => 3.3792,
                    ],
                    'radius' => 50000.0,
                ],
            ];
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location',
        ])->post('https://places.googleapis.com/v1/places:searchText', $payload);

        $response->throw();

        $data = $response->json();
        $places = $data['places'] ?? [];

        $results = [];
        foreach ($places as $place) {
            $location = $place['location'] ?? null;
            if (! is_array($location) || ! isset($location['latitude'], $location['longitude'])) {
                continue;
            }

            $name = $place['displayName']['text'] ?? '';
            $fullAddress = $place['formattedAddress'] ?? $name;

            $results[] = [
                'id' => (string) ($place['id'] ?? ''),
                'name' => $name !== '' ? $name : $fullAddress,
                'address' => $fullAddress,
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
            ];
        }

        return $results;
    }
}
