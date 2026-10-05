<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RideShareResource;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;

class RideShareController extends Controller
{
    public function show(string $rideId, string $token): JsonResponse
    {
        $ride = Ride::where('id', $rideId)
            ->where('share_token', $token)
            ->first();

        if (! $ride) {
            return response()->json(['message' => 'Invalid or expired share link.'], 404);
        }

        if ($ride->isTerminal() && $ride->completed_at?->addHour()->isPast()) {
            return response()->json(['message' => 'This share link has expired.'], 410);
        }

        $ride->load(['vehicleClass', 'driver']);

        return response()->json([
            'ride' => new RideShareResource($ride),
        ]);
    }
}
