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

        if ($ride === null || $dispatchedAt === null) {
            return response()->json(['offer' => null]);
        }

        $offer = RideRequestDispatched::fromRide($driverUserId, $ride, $dispatchedAt)->broadcastWith();
        $expiresAt = $dispatchedAt->copy()->addSeconds($offer['response_timeout_seconds']);
        $secondsRemaining = (int) floor(now()->diffInSeconds($expiresAt, false));

        // The response-timeout job moves the ride on shortly; an offer the
        // driver can no longer accept in time is not worth showing.
        if ($secondsRemaining <= 0) {
            return response()->json(['offer' => null]);
        }

        return response()->json([
            'offer' => [
                ...$offer,
                'expires_at' => $expiresAt->toIso8601String(),
                'seconds_remaining' => $secondsRemaining,
            ],
        ]);
    }
}
