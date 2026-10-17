<?php

namespace App\Http\Controllers\Api\V1\Passenger;

use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RideDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassengerActiveRideController extends Controller
{
    /**
     * The passenger's own ride in progress (`ride`), and the rides they
     * booked for other people that are in progress (`third_party_rides`).
     */
    public function show(Request $request): JsonResponse
    {
        $activeStatuses = array_map(
            static fn (RideStatus $status): string => $status->value,
            RideStatus::activeStatuses(),
        );

        $rides = $request->user()->rides()
            ->whereIn('status', $activeStatuses)
            ->with([
                'city',
                'vehicleClass',
                'passenger',
                'driver.driver.vehicle',
                'stateTransitions',
                'cancelledByUser',
            ])
            ->orderByDesc('created_at')
            ->get();

        $ride = $rides->firstWhere('is_third_party', false);

        return response()->json([
            'ride' => $ride === null ? null : (new RideDetailResource($ride))->resolve($request),
            'third_party_rides' => $rides->where('is_third_party', true)
                ->values()
                ->map(fn ($thirdPartyRide) => (new RideDetailResource($thirdPartyRide))->resolve($request)),
        ]);
    }
}
