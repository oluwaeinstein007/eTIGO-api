<?php

namespace App\Services;

use App\Contracts\PlaceSearchGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PhotonGateway implements PlaceSearchGateway
{
    public function __construct(
        private string $baseUrl = 'https://photon.komoot.io',
    ) {}

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
            'limit' => min(max($limit, 1), 20),
            'lang' => 'en',
        ];

        if ($proximityLat !== null && $proximityLng !== null) {
            $params['lat'] = $proximityLat;
            $params['lon'] = $proximityLng;
        } else {
            $params['lat'] = 6.5244;
            $params['lon'] = 3.3792;
        }

        $response = Http::get("{$this->baseUrl}/api/", $params);

        $response->throw();

        $data = $response->json();
        $features = $data['features'] ?? [];

        $places = [];
        foreach ($features as $feature) {
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }

            $props = $feature['properties'] ?? [];
            $name = $props['name'] ?? '';

            $addressParts = array_filter([
                $props['housenumber'] ?? null,
                $props['street'] ?? null,
                $props['district'] ?? null,
                $props['city'] ?? null,
                $props['state'] ?? null,
                $props['country'] ?? null,
            ]);

            $address = ! empty($addressParts) ? implode(', ', $addressParts) : $name;

            if ($name === '') {
                $name = $address !== '' ? $address : 'Unknown Place';
            }

            $osmId = isset($props['osm_id']) ? "osm.{$props['osm_id']}" : '';

            $places[] = [
                'id' => $osmId,
                'name' => $name,
                'address' => $address !== '' ? $address : $name,
                'latitude' => (float) $coordinates[1],
                'longitude' => (float) $coordinates[0],
            ];
        }

        return $places;
    }

    /**
     * @return array{id: string, place_id: string, name: string, address: string, latitude: float, longitude: float, city: string|null}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        $params = [
            'lat' => $lat,
            'lon' => $lng,
            'lang' => 'en',
        ];

        $response = Http::get("{$this->baseUrl}/reverse/", $params);

        $response->throw();

        $data = $response->json();
        $features = $data['features'] ?? [];

        if (empty($features)) {
            throw new RuntimeException('Photon Reverse Geocoding API returned no results.');
        }

        $props = $features[0]['properties'] ?? [];
        $name = $props['name'] ?? '';

        $addressParts = array_filter([
            $props['housenumber'] ?? null,
            $props['street'] ?? null,
            $props['district'] ?? null,
            $props['city'] ?? null,
            $props['state'] ?? null,
            $props['country'] ?? null,
        ]);

        $address = ! empty($addressParts) ? implode(', ', $addressParts) : $name;
        if ($name === '') {
            $name = $address !== '' ? $address : "{$lat},{$lng}";
        }
        if ($address === '') {
            $address = $name;
        }

        $osmId = isset($props['osm_id']) ? "osm.{$props['osm_id']}" : '';
        $city = $props['city'] ?? $props['district'] ?? $props['state'] ?? null;

        return [
            'id' => $osmId,
            'place_id' => $osmId,
            'name' => $name,
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lng,
            'city' => $city,
        ];
    }
}
