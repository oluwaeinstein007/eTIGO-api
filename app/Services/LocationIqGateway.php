<?php

namespace App\Services;

use App\Contracts\PlaceSearchGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LocationIqGateway implements PlaceSearchGateway
{
    public function __construct(
        private string $apiKey,
        private ?string $country = 'ng',
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
            'key' => $this->apiKey,
            'q' => $cleanQuery,
            'limit' => min(max($limit, 1), 20),
            'format' => 'json',
            'normalizecity' => 1,
        ];

        if ($this->country) {
            $params['countrycodes'] = $this->country;
        }

        if ($proximityLat !== null && $proximityLng !== null) {
            $params['lat'] = $proximityLat;
            $params['lon'] = $proximityLng;
        } else {
            $params['lat'] = 6.5244;
            $params['lon'] = 3.3792;
        }

        $response = Http::get('https://us1.locationiq.com/v1/autocomplete', $params);

        if ($response->status() === 404) {
            return [];
        }

        $response->throw();

        $data = $response->json();
        if (! is_array($data) || isset($data['error'])) {
            return [];
        }

        $places = [];
        foreach ($data as $item) {
            if (! isset($item['lat'], $item['lon'])) {
                continue;
            }

            $name = $item['display_place'] ?? $item['address']['name'] ?? '';
            $fullAddress = $item['display_name'] ?? '';
            $address = $item['display_address'] ?? $fullAddress;

            if ($name === '') {
                $parts = explode(',', $fullAddress);
                $name = trim($parts[0]);
            }

            $places[] = [
                'id' => (string) ($item['place_id'] ?? ''),
                'name' => $name !== '' ? $name : $fullAddress,
                'address' => $address !== '' ? $address : $fullAddress,
                'latitude' => (float) $item['lat'],
                'longitude' => (float) $item['lon'],
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
            'key' => $this->apiKey,
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'json',
            'normalizecity' => 1,
        ];

        $response = Http::get('https://us1.locationiq.com/v1/reverse', $params);

        if ($response->failed()) {
            throw new RuntimeException('LocationIQ Reverse Geocoding API returned no results or failed: '.$response->body());
        }

        $data = $response->json();

        if (empty($data) || ! isset($data['place_id'])) {
            throw new RuntimeException('LocationIQ Reverse Geocoding API returned no results.');
        }

        $fullAddress = $data['display_name'] ?? "{$lat},{$lng}";
        $addr = $data['address'] ?? [];
        $name = $addr['name'] ?? $addr['suburb'] ?? $addr['neighbourhood'] ?? $addr['road'] ?? $fullAddress;
        $city = $addr['city'] ?? $addr['town'] ?? $addr['county'] ?? $addr['state'] ?? null;

        return [
            'id' => (string) $data['place_id'],
            'place_id' => (string) $data['place_id'],
            'name' => $name !== '' ? $name : $fullAddress,
            'address' => $fullAddress,
            'latitude' => (float) ($data['lat'] ?? $lat),
            'longitude' => (float) ($data['lon'] ?? $lng),
            'city' => $city,
        ];
    }
}
