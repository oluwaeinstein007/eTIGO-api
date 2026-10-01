<?php

namespace App\Services;

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\VehicleClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class AppCacheService
{
    private const TTL_SHORT = 300;

    private const TTL_MEDIUM = 1800;

    /**
     * @return Collection<int, City>
     */
    public function activeCities(): Collection
    {
        return Cache::tags(['cities'])->remember('cities:active', self::TTL_MEDIUM, function () {
            return City::where('is_active', true)
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * @return Collection<int, VehicleClass>
     */
    public function cityVehicleClasses(int $cityId): Collection
    {
        return Cache::tags(['cities', 'vehicle_classes'])->remember(
            "city:{$cityId}:vehicle_classes",
            self::TTL_MEDIUM,
            function () use ($cityId) {
                return City::findOrFail($cityId)
                    ->vehicleClasses()
                    ->wherePivot('is_active', true)
                    ->where('vehicle_classes.is_active', true)
                    ->orderBy('city_vehicle_classes.sort_order')
                    ->orderBy('vehicle_classes.name')
                    ->get();
            },
        );
    }

    public function currentPricing(int $cityId, int $vehicleClassId): ?PricingConfig
    {
        return Cache::tags(['pricing'])->remember(
            "pricing:{$cityId}:{$vehicleClassId}:current",
            self::TTL_SHORT,
            fn () => PricingConfig::currentFor($cityId, $vehicleClassId),
        );
    }

    /**
     * @return Collection<int, VehicleClass>
     */
    public function vehicleClasses(): Collection
    {
        return Cache::tags(['vehicle_classes'])->remember('vehicle_classes:all', self::TTL_MEDIUM, function () {
            return VehicleClass::where('is_active', true)->get();
        });
    }

    public function invalidateCities(): void
    {
        Cache::tags(['cities'])->flush();
    }

    public function invalidateVehicleClasses(): void
    {
        Cache::tags(['vehicle_classes'])->flush();
    }

    public function invalidatePricing(): void
    {
        Cache::tags(['pricing'])->flush();
    }

    public function invalidateAll(): void
    {
        Cache::tags(['cities', 'vehicle_classes', 'pricing'])->flush();
    }
}
