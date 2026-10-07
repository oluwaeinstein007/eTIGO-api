<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverActiveRideController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $activeStatuses = array_map(
            static fn (RideStatus $status): string => $status->value,
            RideStatus::activeStatuses(),
        );

        $ride = $request->user()->driverRides()
            ->whereIn('status', $activeStatuses)
            ->with(['city', 'vehicleClass', 'passenger', 'driver', 'stateTransitions', 'cancelledByUser'])
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'ride' => $ride === null ? null : (new RideDetailResource($ride))->resolve($request),
        ]);
    }
}
