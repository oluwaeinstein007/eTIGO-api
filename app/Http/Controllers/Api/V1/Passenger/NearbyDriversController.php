<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyDriversController extends Controller
{
    private const int MAX_LOCATION_AGE_SECONDS = 120;

    public function __invoke(Request $request, DriverLocationService $locations): JsonResponse
    {
        $input = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'numeric', 'gt:0', 'max:50'],
        ]);
        $lat = (float) $input['lat'];
        $lng = (float) $input['lng'];
        $radius = (float) ($input['radius_km'] ?? 10);
        $nearby = $locations->findNearbyDrivers($lat, $lng, $radius, 50);
        $ids = array_column($nearby, 'driver_id');
        $distances = collect($nearby)->keyBy('driver_id');
        $drivers = Driver::query()
            ->whereIn('id', $ids)
            ->where('status', DriverStatus::Approved)
            ->where('is_online', true)
            ->with('vehicle.vehicleClass')
            ->get()
            ->map(function (Driver $driver) use ($locations, $distances) {
                $location = $locations->getDriverLocation((string) $driver->id);
                if (! $location || $location['timestamp'] < now()->subSeconds(self::MAX_LOCATION_AGE_SECONDS)->timestamp) {
                    return null;
                }

                return [
                    'driver_id' => (string) $driver->id,
                    'lat' => $location['lat'],
                    'lng' => $location['lng'],
                    'heading' => $location['heading'],
                    'timestamp' => $location['timestamp'],
                    'distance_km' => (float) ($distances[$driver->id]['distance_km'] ?? 0),
                    'vehicle_class' => $driver->vehicle?->vehicleClass?->display_name
                        ?? $driver->vehicle?->vehicleClass?->name,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['drivers' => $drivers]);
    }
}
