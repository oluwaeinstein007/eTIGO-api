<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ride;
use App\Services\DriverLocationService;
use App\Services\EtaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RideLocationController extends Controller
{
    public function __construct(
        private DriverLocationService $locationService,
        private EtaService $etaService,
    ) {}

    public function show(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        if (! $this->canViewRideLocation($user, $ride)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (! $ride->driver_id) {
            return response()->json([
                'message' => 'No driver assigned to this ride.',
                'location' => null,
                'eta' => null,
            ]);
        }

        $location = $this->locationService->getDriverLocation($ride->driver_id);

        if (! $location) {
            return response()->json([
                'message' => 'Driver location unavailable.',
                'location' => null,
                'eta' => null,
            ]);
        }

        $eta = $this->etaService->getEtaForRide(
            $ride,
            $location['lat'],
            $location['lng'],
        );

        return response()->json([
            'location' => [
                'lat' => $location['lat'],
                'lng' => $location['lng'],
                'heading' => $location['heading'],
                'speed' => $location['speed'],
                'timestamp' => $location['timestamp'],
            ],
            'eta' => $eta,
            'ride_status' => $ride->status->value,
        ]);
    }

    private function canViewRideLocation($user, Ride $ride): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $ride->passenger_id === $user->id
            || $ride->driver_id === $user->id;
    }
}
