<?php

namespace App\Services;

use App\Contracts\PlaceSearchGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeoapifyGateway implements PlaceSearchGateway
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
            'text' => $cleanQuery,
            'apiKey' => $this->apiKey,
            'limit' => min(max($limit, 1), 20),
            'lang' => 'en',
        ];

        if ($this->country) {
            $params['filter'] = "countrycode:{$this->country}";
        }

        if ($proximityLat !== null && $proximityLng !== null) {
            $params['bias'] = "proximity:{$proximityLng},{$proximityLat}";
        } else {
            $params['bias'] = 'proximity:3.3792,6.5244';
        }

        $response = Http::get('https://api.geoapify.com/v1/geocode/autocomplete', $params);

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
            $name = $props['name'] ?? $props['address_line1'] ?? $props['formatted'] ?? '';
            $formatted = $props['formatted'] ?? $name;
            $address = $props['address_line2'] ?? $formatted;

            if ($name === '' && $formatted !== '') {
                $name = $formatted;
            }
            if ($address === '' && $formatted !== '') {
                $address = $formatted;
            }

            $places[] = [
                'id' => (string) ($props['place_id'] ?? ''),
                'name' => $name,
                'address' => $address,
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
            'apiKey' => $this->apiKey,
            'limit' => 1,
            'lang' => 'en',
        ];

        $response = Http::get('https://api.geoapify.com/v1/geocode/reverse', $params);

        $response->throw();

        $data = $response->json();
        $features = $data['features'] ?? [];

        if (empty($features)) {
            throw new RuntimeException('Geoapify Reverse Geocoding API returned no results.');
        }

        $props = $features[0]['properties'] ?? [];
        $formatted = $props['formatted'] ?? "{$lat},{$lng}";
        $name = $props['name'] ?? $props['address_line1'] ?? $formatted;
        $city = $props['city'] ?? $props['county'] ?? $props['state'] ?? null;

        return [
            'id' => (string) ($props['place_id'] ?? ''),
            'place_id' => (string) ($props['place_id'] ?? ''),
            'name' => $name !== '' ? $name : $formatted,
            'address' => $formatted,
            'latitude' => $lat,
            'longitude' => $lng,
            'city' => $city,
        ];
    }
}
