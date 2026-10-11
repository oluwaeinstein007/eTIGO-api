<?php

namespace App\Services;

use App\Contracts\PlaceSearchGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

class FailoverPlaceSearchGateway implements PlaceSearchGateway
{
    /**
     * @param  list<PlaceSearchGateway>  $drivers
     */
    public function __construct(
        private array $drivers,
    ) {}

    /**
     * @return list<PlaceSearchGateway>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
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
        foreach ($this->drivers as $driver) {
            try {
                $results = $driver->searchPlaces($query, $proximityLat, $proximityLng, $limit);
                if (! empty($results)) {
                    return $results;
                }
            } catch (Throwable $e) {
                Log::warning('PlaceSearch driver failed during searchPlaces', [
                    'driver' => get_class($driver),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [];
    }

    /**
     * @return array{id: string, place_id: string, name: string, address: string, latitude: float, longitude: float, city: string|null}
     */
    public function reverseGeocode(float $lat, float $lng): array
    {
        foreach ($this->drivers as $driver) {
            try {
                return $driver->reverseGeocode($lat, $lng);
            } catch (Throwable $e) {
                Log::warning('PlaceSearch driver failed during reverseGeocode', [
                    'driver' => get_class($driver),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'id' => '',
            'place_id' => '',
            'name' => "{$lat},{$lng}",
            'address' => "{$lat},{$lng}",
            'latitude' => $lat,
            'longitude' => $lng,
            'city' => null,
        ];
    }
}
