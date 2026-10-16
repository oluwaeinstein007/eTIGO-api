<?php

namespace App\Services;

use App\Models\City;
use App\Models\PricingConfig;
use App\Models\VehicleClass;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds vehicle classes that passengers can pick in a city but that have no
 * pricing in effect. Fare estimates skip those classes, so a city where every
 * class is unpriced returns no estimates at all. Admin endpoints use the
 * warnings here to flag the gap when it is created rather than when a
 * passenger hits it.
 */
class PricingCoverageService
{
    /**
     * City/class pairs that are offered (active for the city and active
     * overall) but have no pricing config in effect now.
     *
     * @param  list<string>  $cityIds  Limit to these cities (all when empty)
     * @param  list<string>  $vehicleClassIds  Limit to these classes (all when empty)
     * @return Collection<int, array{city_id: string, city_name: string, city_is_active: bool, vehicle_class_id: string, vehicle_class_name: string, next_effective_from: string|null}>
     */
    public function gaps(array $cityIds = [], array $vehicleClassIds = []): Collection
    {
        $now = now();

        return DB::table('city_vehicle_classes as cvc')
            ->join('cities', 'cities.id', '=', 'cvc.city_id')
            ->join('vehicle_classes', 'vehicle_classes.id', '=', 'cvc.vehicle_class_id')
            ->where('cvc.is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->when($cityIds !== [], fn ($q) => $q->whereIn('cvc.city_id', $cityIds))
            ->when($vehicleClassIds !== [], fn ($q) => $q->whereIn('cvc.vehicle_class_id', $vehicleClassIds))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('pricing_configs')
                ->whereColumn('pricing_configs.city_id', 'cvc.city_id')
                ->whereColumn('pricing_configs.vehicle_class_id', 'cvc.vehicle_class_id')
                ->where('pricing_configs.effective_from', '<=', $now))
            ->select([
                'cities.id as city_id',
                'cities.name as city_name',
                'cities.is_active as city_is_active',
                'vehicle_classes.id as vehicle_class_id',
                DB::raw('COALESCE(vehicle_classes.display_name, vehicle_classes.name) as vehicle_class_name'),
            ])
            ->selectSub(fn ($q) => $q->from('pricing_configs')
                ->whereColumn('pricing_configs.city_id', 'cvc.city_id')
                ->whereColumn('pricing_configs.vehicle_class_id', 'cvc.vehicle_class_id')
                ->selectRaw('MIN(effective_from)'), 'next_effective_from')
            ->orderBy('cities.name')
            ->orderBy('vehicle_class_name')
            ->get()
            ->map(fn ($row) => [
                'city_id' => $row->city_id,
                'city_name' => $row->city_name,
                'city_is_active' => (bool) $row->city_is_active,
                'vehicle_class_id' => $row->vehicle_class_id,
                'vehicle_class_name' => $row->vehicle_class_name,
                'next_effective_from' => $row->next_effective_from
                    ? Carbon::parse($row->next_effective_from)->toIso8601String()
                    : null,
            ]);
    }

    /**
     * @return list<string>
     */
    public function warningsForCity(City $city): array
    {
        $offered = $city->vehicleClasses()
            ->wherePivot('is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->count();

        if ($city->is_active && $offered === 0) {
            return ["{$city->name} is active but doesn't offer any vehicle classes, so passengers can't book rides there."];
        }

        $gaps = $this->gaps([$city->id]);

        if ($gaps->isEmpty()) {
            return [];
        }

        $names = $this->listNames($gaps->pluck('vehicle_class_name'));

        if ($city->is_active && $gaps->count() >= $offered) {
            return ["Passengers in {$city->name} can't get fare estimates: none of its vehicle classes ({$names}) has pricing in effect. Add pricing for at least one of them."];
        }

        return ["{$city->name} has no pricing in effect for {$names}. Passengers won't get fares for these classes until pricing is added."];
    }

    /**
     * @return list<string>
     */
    public function warningsForVehicleClass(VehicleClass $vehicleClass): array
    {
        $name = $vehicleClass->display_name ?: $vehicleClass->name;

        if (! $vehicleClass->is_active) {
            $stranded = $vehicleClass->cities()
                ->wherePivot('is_active', true)
                ->where('cities.is_active', true)
                ->orderBy('cities.name')
                ->get()
                ->reject(fn (City $city) => $this->hasBookableClass($city));

            return $stranded->isEmpty() ? [] : [
                "{$name} is turned off, which leaves {$this->listNames($stranded->pluck('name'))} with no vehicle class passengers can book.",
            ];
        }

        $gaps = $this->gaps([], [$vehicleClass->id]);

        if ($gaps->isEmpty()) {
            return [];
        }

        return ["{$name} has no pricing in effect in {$this->listNames($gaps->pluck('city_name'))}. Passengers there won't get fares for it until pricing is added."];
    }

    /**
     * Reasons a pricing config won't be used when passengers ask for fares.
     *
     * @return list<string>
     */
    public function warningsForPricingConfig(PricingConfig $config): array
    {
        $config->loadMissing(['city', 'vehicleClass']);
        $city = $config->city;
        $vehicleClass = $config->vehicleClass;
        $name = $vehicleClass->display_name ?: $vehicleClass->name;
        $warnings = [];

        if (! $vehicleClass->is_active) {
            $warnings[] = "{$name} is turned off, so this pricing won't be used until the vehicle class is turned on.";
        }

        $offeredInCity = $city->vehicleClasses()
            ->where('vehicle_classes.id', $vehicleClass->id)
            ->wherePivot('is_active', true)
            ->exists();

        if (! $offeredInCity) {
            $warnings[] = "{$name} isn't offered in {$city->name}, so this pricing won't be used until the class is turned on for that city.";
        }

        if ($config->effective_from->isFuture() && ! PricingConfig::currentFor($city->id, $vehicleClass->id)) {
            $startsAt = $config->effective_from->timezone($city->timezone ?: config('app.timezone'))->format('j M Y, H:i');
            $warnings[] = "This pricing starts on {$startsAt}. Until then {$name} has no pricing in {$city->name}, so passengers won't get fares for it.";
        }

        return $warnings;
    }

    /**
     * Whether passengers in the city can get a fare for at least one class.
     */
    private function hasBookableClass(City $city): bool
    {
        return $city->vehicleClasses()
            ->wherePivot('is_active', true)
            ->where('vehicle_classes.is_active', true)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('pricing_configs')
                ->whereColumn('pricing_configs.city_id', 'city_vehicle_classes.city_id')
                ->whereColumn('pricing_configs.vehicle_class_id', 'city_vehicle_classes.vehicle_class_id')
                ->where('pricing_configs.effective_from', '<=', now()))
            ->exists();
    }

    /**
     * @param  Collection<int, string>  $names
     */
    private function listNames(Collection $names): string
    {
        return Arr::join($names->unique()->values()->all(), ', ', ' and ');
    }
}
