<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RideShareResource;
use App\Models\Ride;
use App\Services\DriverLocationService;
use App\Services\EtaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class RideShareController extends Controller
{
    private const int LOCATION_MAX_AGE_SECONDS = 120;

    public function __construct(
        private DriverLocationService $locationService,
        private EtaService $etaService,
    ) {}

    public function show(string $rideId, string $token): JsonResponse
    {
        $ride = Str::isUuid($rideId)
            ? Ride::where('id', $rideId)->where('share_token', $token)->first()
            : null;

        if (! $ride) {
            return response()->json(['message' => 'Invalid or expired share link.'], 404);
        }

        if ($ride->isTerminal()) {
            $expiredAt = $ride->completed_at ?? $ride->updated_at;

            if ($expiredAt?->addHour()->isPast()) {
                return response()->json(['message' => 'This share link has expired.'], 410);
            }
        }

        $ride->load(['vehicleClass', 'driver.driver.vehicle', 'passenger']);

        [$location, $eta] = $this->liveLocation($ride);

        return response()->json([
            'ride' => new RideShareResource($ride),
            'driver_location' => $location,
            'eta' => $eta,
            'safety' => [
                'emergency_number' => (string) config('sos.emergency_number', '112'),
                'safety_line' => config('sos.safety_line'),
            ],
        ]);
    }

    /**
     * The driver's position and ETA while the ride is under way; nothing
     * before a driver is assigned or after the trip ends.
     *
     * @return array{0: array{lat: float, lng: float, heading: mixed, timestamp: int}|null, 1: array<string, mixed>|null}
     */
    private function liveLocation(Ride $ride): array
    {
        if ($ride->driver_id === null || ! $ride->isActive()) {
            return [null, null];
        }

        try {
            $location = $this->locationService->getDriverLocation($ride->driver_id);
        } catch (\Throwable) {
            return [null, null];
        }

        // An old position would show the car where it no longer is.
        if ($location === null || $location['timestamp'] < now()->subSeconds(self::LOCATION_MAX_AGE_SECONDS)->timestamp) {
            return [null, null];
        }

        try {
            $eta = $this->etaService->getThrottledEta($ride, $location['lat'], $location['lng']);
        } catch (\Throwable) {
            $eta = null;
        }

        return [[
            'lat' => $location['lat'],
            'lng' => $location['lng'],
            'heading' => $location['heading'] ?? null,
            'timestamp' => $location['timestamp'],
        ], $eta];
    }
}
