<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverStatus;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ride\AssignRideRequest;
use App\Http\Resources\RideResource;
use App\Models\Driver;
use App\Models\Ride;
use App\Models\User;
use App\Services\RideService;
use Illuminate\Http\JsonResponse;

class AdminRideController extends Controller
{
    public function __construct(
        private RideService $rideService,
    ) {}

    public function assign(AssignRideRequest $request, Ride $ride): JsonResponse
    {
        $admin = $request->user();

        if (! in_array($ride->status, [RideStatus::Searching, RideStatus::Requested])) {
            return response()->json([
                'message' => 'This ride cannot be assigned in its current state.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $driver = Driver::with('vehicle', 'user')
            ->where('user_id', $request->integer('driver_id'))
            ->first();

        if (! $driver) {
            return response()->json(['message' => 'Driver not found.'], 404);
        }

        $ride = $this->rideService->adminAssignDriver($ride, $driver->user, $admin);
        $ride->load(['city', 'vehicleClass', 'passenger', 'driver']);

        return response()->json([
            'message' => 'Driver assigned successfully.',
            'ride' => new RideResource($ride),
        ]);
    }
}
