<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Events\RideRequestDispatched;
use App\Http\Controllers\Controller;
use App\Services\DriverMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverRideOfferController extends Controller
{
    /**
     * The ride offer the driver has not answered yet, for an app that opens
     * from a notification or comes back to the foreground after missing the
     * ride.request.dispatched event.
     */
    public function show(Request $request, DriverMatchingService $matchingService): JsonResponse
    {
        $driverUserId = $request->user()->id;
        $ride = $matchingService->currentOfferFor($driverUserId);
        $dispatchedAt = $ride ? $matchingService->getDispatchedAt($ride) : null;
        $expiresAt = $ride ? $matchingService->getOfferExpiresAt($ride) : null;

        if ($ride === null || $dispatchedAt === null || $expiresAt === null) {
            return response()->json(['offer' => null]);
        }

        $secondsRemaining = (int) floor(now()->diffInSeconds($expiresAt, false));

        // Past the deadline accept refuses the offer, even while the
        // response-timeout job has yet to move the ride on.
        if ($secondsRemaining <= 0) {
            return response()->json(['offer' => null]);
        }

        $offer = RideRequestDispatched::fromRide($driverUserId, $ride, $dispatchedAt)->broadcastWith();

        return response()->json([
            'offer' => [
                ...$offer,
                'expires_at' => $expiresAt->toIso8601String(),
                'seconds_remaining' => $secondsRemaining,
            ],
        ]);
    }
}
