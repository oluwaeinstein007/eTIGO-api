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
     * @return array{address: string, place_id: string}
     */
    public function reverseGeocode(float $lat, float $lng): array;
}
