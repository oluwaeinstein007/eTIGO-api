<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleMapsGateway implements MapsGateway
{
    public function __construct(
        private string $apiKey,
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
        $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
            'origins' => "{$originLat},{$originLng}",
            'destinations' => "{$destinationLat},{$destinationLng}",
            'units' => 'metric',
            'key' => $this->apiKey,
        ]);

        $response->throw();

        $data = $response->json();

        if (($data['status'] ?? '') !== 'OK') {
            throw new RuntimeException("Google Maps API error: {$data['status']}");
        }

        $element = $data['rows'][0]['elements'][0] ?? null;

        if (! $element || ($element['status'] ?? '') !== 'OK') {
            throw new RuntimeException('Google Maps API returned no route: '.($element['status'] ?? 'UNKNOWN'));
        }

        return [
            'distance_km' => round($element['distance']['value'] / 1000, 2),
            'duration_minutes' => round($element['duration']['value'] / 60, 1),
        ];
    }

    /**
     * @return array{lat: float, lng: float, formatted_address: string}
     */
    public function geocode(string $address): array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $address,
            'key' => $this->apiKey,
        ]);

        $response->throw();

        $data = $response->json();

        if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
            throw new RuntimeException("Geocoding failed: {$data['status']}");
        }

        $result = $data['results'][0];
        $location = $result['geometry']['location'];

        return [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
            'formatted_address' => $result['formatted_address'],
        ];
    }

    /**
     * @return array{address: string, place_id: string}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => "{$lat},{$lng}",
            'key' => $this->apiKey,
        ]);

        $response->throw();

        $data = $response->json();

        if (($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
            throw new RuntimeException("Reverse geocoding failed: {$data['status']}");
        }

        $result = $data['results'][0];

        return [
            'address' => $result['formatted_address'],
            'place_id' => $result['place_id'],
        ];
    }
}
