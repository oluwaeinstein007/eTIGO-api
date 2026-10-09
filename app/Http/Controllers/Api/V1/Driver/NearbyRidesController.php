<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\DriverLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyRidesController extends Controller
{
    public function __invoke(Request $request, DriverLocationService $locations): JsonResponse
    {
        $input = $request->validate([
            'radius_km' => ['sometimes', 'numeric', 'gt:0', 'max:50'],
        ]);
        $driver = $request->user()->driver;
        if (! $driver || $driver->status !== DriverStatus::Approved || ! $driver->is_online) {
            return response()->json(['rides' => []]);
        }
        if (! $driver->vehicle || Ride::where('driver_id', $request->user()->id)
            ->whereIn('status', [RideStatus::Matched, RideStatus::DriverEnRoute, RideStatus::DriverArrived, RideStatus::InProgress])
            ->exists()) {
            return response()->json(['rides' => []]);
        }

        $location = $locations->getDriverLocation((string) $driver->id);
        if (! $location || $location['timestamp'] < now()->subSeconds(120)->timestamp) {
            return response()->json(['rides' => []]);
        }

        $radius = (float) ($input['radius_km'] ?? 10);
        $latDelta = $radius / 110.574;
        $lngDelta = $radius / max(111.320 * cos(deg2rad($location['lat'])), 0.01);
        $rides = Ride::query()
            ->where('status', RideStatus::Searching)
            ->whereNull('driver_id')
            ->where('city_id', $driver->city_id)
            ->where('vehicle_class_id', $driver->vehicle->vehicle_class_id)
            ->whereBetween('pickup_lat', [$location['lat'] - $latDelta, $location['lat'] + $latDelta])
            ->whereBetween('pickup_lng', [$location['lng'] - $lngDelta, $location['lng'] + $lngDelta])
            ->limit(200)
            ->get()
            ->map(function (Ride $ride) use ($location, $radius) {
                $distance = $this->distanceKm(
                    $location['lat'], $location['lng'],
                    (float) $ride->pickup_lat, (float) $ride->pickup_lng,
                );
                if ($distance > $radius) {
                    return null;
                }

                return [
                    'ride_id' => (string) $ride->id,
                    'pickup_lat' => (float) $ride->pickup_lat,
                    'pickup_lng' => (float) $ride->pickup_lng,
                    'distance_km' => round($distance, 3),
                    'created_at' => $ride->created_at?->toISOString(),
                ];
            })
            ->filter()
            ->sortBy('distance_km')
            ->values();

        return response()->json(['rides' => $rides]);
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
