<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\RideStatus;
use App\Events\DriverLocationUpdated;
use App\Events\NearbyMapChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\LocationUpdateRequest;
use App\Models\Ride;
use App\Services\DriverLocationService;
use App\Services\EtaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class DriverLocationController extends Controller
{
    public function __construct(
        private DriverLocationService $locationService,
        private EtaService $etaService,
    ) {}

    public function update(LocationUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $driver = $user->driver;

        if (! $driver || ! $driver->isApproved() || ! $driver->is_online) {
            return response()->json([
                'message' => 'Only online, approved drivers can update location.',
            ], 403);
        }

        $rateLimitKey = "driver_location:{$driver->id}";

        if (RateLimiter::hit($rateLimitKey, 1) > 1) {
            return response()->json([
                'message' => 'Location updates limited to once per second.',
            ], 429);
        }

        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');
        $heading = (float) $request->input('heading', 0);
        $speed = (float) $request->input('speed', 0);

        $this->locationService->updateLocation($driver->id, $lat, $lng, $heading, $speed);

        $location = [
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'speed' => $speed,
            'timestamp' => now()->timestamp,
        ];

        $activeRide = Ride::where('driver_id', $user->id)
            ->whereIn('status', [
                RideStatus::DriverEnRoute,
                RideStatus::DriverArrived,
                RideStatus::InProgress,
            ])
            ->first();

        $eta = null;
        $rideId = null;

        if ($activeRide) {
            $rideId = $activeRide->id;
            $eta = $this->etaService->getThrottledEta($activeRide, $lat, $lng);
        }

        DriverLocationUpdated::dispatch($driver->id, $location, $rideId, $eta);
        try {
            if (Cache::add('nearby_map:passengers', true, 5)) {
                NearbyMapChanged::dispatch('passengers');
            }
            if (Cache::add('nearby_map:drivers', true, 5)) {
                NearbyMapChanged::dispatch('drivers');
            }
        } catch (\Throwable) {
            // Realtime map refresh must not fail location tracking.
        }

        return response()->json([
            'message' => 'Location updated.',
            'eta' => $eta,
        ]);
    }
}
