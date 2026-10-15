<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use App\Models\Ride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RideRouteService
{
    private const CACHE_SECONDS = 86400;

    public function __construct(
        private MapsGateway $mapsGateway,
    ) {}

    /**
     * The planned driving route from pickup to destination. It is cached per
     * ride (keyed on the coordinates, so a changed destination is re-routed)
     * and is null when the routing provider fails.
     *
     * @return array{encoded_polyline: string, distance_km: float, duration_minutes: float}|null
     */
    public function getPlannedRoute(Ride $ride): ?array
    {
        $coordinates = [
            (float) $ride->pickup_lat,
            (float) $ride->pickup_lng,
            (float) $ride->destination_lat,
            (float) $ride->destination_lng,
        ];

        $cacheKey = "ride_route:{$ride->id}:".md5(implode(',', $coordinates));

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $route = $this->mapsGateway->getRoute(...$coordinates);
        } catch (\Throwable $e) {
            Log::warning('Ride route lookup failed', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($route['encoded_polyline'] === '') {
            return null;
        }

        Cache::put($cacheKey, $route, self::CACHE_SECONDS);

        return $route;
    }
}
