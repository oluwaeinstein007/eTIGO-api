<?php

namespace App\Services;

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AppCacheService
{
    private const TTL_SHORT = 300;

    private const TTL_MEDIUM = 1800;

    /**
     * @return Collection<int, City>
     */
    public function activeCities(): Collection
    {
        return $this->safeRemember(['cities'], 'cities:active', self::TTL_MEDIUM, function () {
            return City::where('is_active', true)
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * @return Collection<int, VehicleClass>
     */
    public function cityVehicleClasses(string $cityId): Collection
    {
        return $this->safeRemember(['cities', 'vehicle_classes'], "city:{$cityId}:vehicle_classes", self::TTL_MEDIUM, function () use ($cityId) {
            return City::findOrFail($cityId)
                ->vehicleClasses()
                ->wherePivot('is_active', true)
                ->where('vehicle_classes.is_active', true)
                ->orderBy('city_vehicle_classes.sort_order')
                ->orderBy('vehicle_classes.name')
                ->get();
        });
    }

    public function currentPricing(string $cityId, string $vehicleClassId): ?PricingConfig
    {
        return $this->safeRemember(['pricing'], "pricing:{$cityId}:{$vehicleClassId}:current", self::TTL_SHORT, function () use ($cityId, $vehicleClassId) {
            return PricingConfig::currentFor($cityId, $vehicleClassId);
        });
    }

    /**
     * @return Collection<int, VehicleClass>
     */
    public function vehicleClasses(): Collection
    {
        return $this->safeRemember(['vehicle_classes'], 'vehicle_classes:all', self::TTL_MEDIUM, function () {
            return VehicleClass::where('is_active', true)->get();
        });
    }

    public function invalidateCities(): void
    {
        $this->safeFlush(['cities']);
    }

    public function invalidateVehicleClasses(): void
    {
        $this->safeFlush(['vehicle_classes']);
    }

    public function invalidatePricing(): void
    {
        $this->safeFlush(['pricing']);
    }

    public function invalidateAll(): void
    {
        $this->safeFlush(['cities', 'vehicle_classes', 'pricing']);
    }

    /**
     * @template T
     *
     * @param  list<string>  $tags
     * @param  callable(): T  $callback
     * @return T
     */
    private function safeRemember(array $tags, string $key, int $ttl, callable $callback): mixed
    {
        try {
            $result = Cache::tags($tags)->remember($key, $ttl, $callback);

            if ($result instanceof \__PHP_Incomplete_Class) {
                Cache::tags($tags)->forget($key);

                return $callback();
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Cache read failed, falling back to database.', [
                'key' => $key,
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * @param  list<string>  $tags
     */
    private function safeFlush(array $tags): void
    {
        try {
            Cache::tags($tags)->flush();
        } catch (\Throwable $e) {
            Log::warning('Cache flush failed.', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
