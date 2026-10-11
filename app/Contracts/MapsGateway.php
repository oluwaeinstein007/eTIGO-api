<?php

namespace App\Contracts;

interface MapsGateway
{
    /**
     * @return array{distance_km: float, duration_minutes: float}
     */
    public function getDistanceAndDuration(
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng,
    ): array;

    /**
     * @return array{lat: float, lng: float, formatted_address: string}
     */
    public function geocode(string $address): array;

    /**
     * @return array{address: string, place_id: string, city: string|null, name?: string, latitude?: float, longitude?: float}
     */
    public function reverseGeocode(float $lat, float $lng): array;

    /**
     * @return list<array{id: string, name: string, address: string, latitude: float, longitude: float}>
     */
    public function searchPlaces(
        string $query,
        ?float $proximityLat = null,
        ?float $proximityLng = null,
        int $limit = 10,
    ): array;
}
