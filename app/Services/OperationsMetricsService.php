<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Models\Driver;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Builder;

/**
 * The live operations counts, defined once so the overview's "now" and the
 * snapshots it compares against ("this time yesterday") count the same way.
 *
 * - active rides: requested through in progress (RideStatus::activeStatuses);
 * - drivers online: approved and online, as on live monitoring;
 * - on trip: online drivers holding a matched-to-in-progress ride.
 */
class OperationsMetricsService
{
    /**
     * @return array<string, array{active_rides: int, drivers_online: int, drivers_on_trip: int}> keyed by city id ('' for none)
     */
    public function countsByCity(): array
    {
        $counts = [];
        $add = function (?string $cityId, string $metric, int $value) use (&$counts) {
            $key = $cityId ?? '';
            $counts[$key] ??= ['active_rides' => 0, 'drivers_online' => 0, 'drivers_on_trip' => 0];
            $counts[$key][$metric] = $value;
        };

        $this->activeRides()
            ->selectRaw('city_id, COUNT(*) AS total')
            ->groupBy('city_id')
            ->get()
            ->each(fn ($row) => $add($row->city_id, 'active_rides', (int) $row->total));

        $this->onlineDrivers()
            ->selectRaw('city_id, COUNT(*) AS total')
            ->groupBy('city_id')
            ->get()
            ->each(fn ($row) => $add($row->city_id, 'drivers_online', (int) $row->total));

        $this->driversOnTrip()
            ->selectRaw('city_id, COUNT(*) AS total')
            ->groupBy('city_id')
            ->get()
            ->each(fn ($row) => $add($row->city_id, 'drivers_on_trip', (int) $row->total));

        return $counts;
    }

    /**
     * @return array{active_rides: int, drivers_online: int, drivers_on_trip: int}
     */
    public function totals(): array
    {
        return [
            'active_rides' => $this->activeRides()->count(),
            'drivers_online' => $this->onlineDrivers()->count(),
            'drivers_on_trip' => $this->driversOnTrip()->count(),
        ];
    }

    /**
     * @return Builder<Ride>
     */
    public function activeRides(): Builder
    {
        return Ride::query()->whereIn('status', RideStatus::activeStatuses());
    }

    /**
     * @return Builder<Driver>
     */
    public function onlineDrivers(): Builder
    {
        return Driver::query()
            ->where('status', DriverStatus::Approved)
            ->where('is_online', true);
    }

    /**
     * @return Builder<Driver>
     */
    public function driversOnTrip(): Builder
    {
        return $this->onlineDrivers()->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('rides')
            ->whereColumn('rides.driver_id', 'drivers.user_id')
            ->whereIn('rides.status', array_map(fn (RideStatus $s) => $s->value, RideStatus::driverOnTripStatuses())));
    }
}
