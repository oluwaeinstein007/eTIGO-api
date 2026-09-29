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
}
