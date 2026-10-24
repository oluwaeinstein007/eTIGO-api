<?php

namespace App\Services;

use App\Contracts\MapsGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routing and geocoding through the configured providers in order (Google,
 * then Mapbox), ending with the straight-line gateway. Routing and reverse
 * geocoding always get an answer, so a provider's rate limit or outage does
 * not fail fare estimates, bookings or city detection; geocoding an address
 * fails only when every real provider does. Each provider that fails is
 * logged as a warning and the next is tried.
 */
class FailoverMapsGateway implements MapsGateway
{
    /**
     * @param  list<MapsGateway>  $drivers  in order; the last should not fail (HaversineMapsGateway)
     */
    public function __construct(
        private array $drivers,
    ) {}

    /**
     * @return list<MapsGateway>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    public function getDistanceAndDuration(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        return $this->first('getDistanceAndDuration', fn (MapsGateway $driver) => $driver->getDistanceAndDuration($originLat, $originLng, $destinationLat, $destinationLng));
    }

    public function getRoute(float $originLat, float $originLng, float $destinationLat, float $destinationLng): array
    {
        return $this->first('getRoute', fn (MapsGateway $driver) => $driver->getRoute($originLat, $originLng, $destinationLat, $destinationLng));
    }

    public function geocode(string $address): array
    {
        return $this->first('geocode', fn (MapsGateway $driver) => $driver->geocode($address));
    }

    public function reverseGeocode(float $lat, float $lng): array
    {
        return $this->first('reverseGeocode', fn (MapsGateway $driver) => $driver->reverseGeocode($lat, $lng));
    }

    public function searchPlaces(string $query, ?float $proximityLat = null, ?float $proximityLng = null, int $limit = 10): array
    {
        foreach ($this->drivers as $driver) {
            try {
                $results = $driver->searchPlaces($query, $proximityLat, $proximityLng, $limit);

                if ($results !== []) {
                    return $results;
                }
            } catch (Throwable $e) {
                $this->warn('searchPlaces', $driver, $e);
            }
        }

        return [];
    }

    /**
     * The first provider's answer, trying the next when one throws. When
     * every provider fails, the last failure is rethrown.
     *
     * @template T
     *
     * @param  callable(MapsGateway): T  $call
     * @return T
     */
    private function first(string $operation, callable $call): mixed
    {
        $last = null;

        foreach ($this->drivers as $driver) {
            try {
                return $call($driver);
            } catch (Throwable $e) {
                $this->warn($operation, $driver, $e);
                $last = $e;
            }
        }

        throw $last ?? new \RuntimeException("No maps provider is configured for {$operation}.");
    }

    private function warn(string $operation, MapsGateway $driver, Throwable $e): void
    {
        Log::warning("Maps provider failed during {$operation}; trying the next one.", [
            'driver' => $driver::class,
            'error' => $e->getMessage(),
        ]);
    }
}
