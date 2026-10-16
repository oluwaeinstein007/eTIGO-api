<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ride\CancelRideRequest;
use App\Http\Requests\Ride\UpdateScheduledRideRequest;
use App\Http\Resources\RideResource;
use App\Models\Ride;
use App\Services\RideService;
use App\Services\ScheduledRideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A passenger's rides booked for later (Phase 2). Booking itself is
 * POST /rides with scheduled_pickup_at.
 */
class ScheduledRideController extends Controller
{
    public function __construct(
        private ScheduledRideService $scheduledRideService,
        private RideService $rideService,
    ) {}

    /**
     * Upcoming bookings that have not started looking for a driver yet,
     * soonest first.
     */
    public function index(Request $request): JsonResponse
    {
        $rides = Ride::query()
            ->where('passenger_id', $request->user()->id)
            ->where('status', RideStatus::Scheduled)
            ->join('scheduled_rides', 'scheduled_rides.ride_id', '=', 'rides.id')
            ->orderBy('scheduled_rides.scheduled_pickup_at')
            ->select('rides.*')
            ->with(['city', 'vehicleClass', 'promoCode', 'scheduledRide.reservedDriver.driver.vehicle'])
            ->paginate($this->perPage($request, 15, 50));

        return response()->json([
            'rides' => RideResource::collection($rides),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }

    public function update(UpdateScheduledRideRequest $request, Ride $ride): JsonResponse
    {
        if (! $ride->belongsToPassenger($request->user())) {
            return response()->json(['message' => 'You are not authorized to manage this ride.'], 403);
        }

        $ride = $this->scheduledRideService->update($ride, $request->user(), $request->validated());
        $ride->load(['city', 'vehicleClass', 'promoCode', 'scheduledRide.reservedDriver.driver.vehicle']);

        return response()->json([
            'message' => 'Scheduled ride updated.',
            'ride' => new RideResource($ride),
        ]);
    }

    /**
     * Cancel a booking before it starts looking for a driver. Free, like any
     * cancellation in Phase 1. Once dispatched, use POST /rides/{ride}/cancel.
     */
    public function destroy(CancelRideRequest $request, Ride $ride): JsonResponse
    {
        if (! $ride->belongsToPassenger($request->user())) {
            return response()->json(['message' => 'You are not authorized to manage this ride.'], 403);
        }

        if ($ride->status !== RideStatus::Scheduled) {
            return response()->json([
                'message' => 'Only a scheduled ride that has not started can be cancelled here.',
                'current_status' => $ride->status->value,
            ], 422);
        }

        $ride = $this->rideService->cancelRide(
            $ride,
            $request->user(),
            $request->input('reason'),
            $request->input('reason_details'),
        );
        $ride->load(['city', 'vehicleClass', 'cancelledByUser', 'scheduledRide']);

        return response()->json([
            'message' => 'Scheduled ride cancelled.',
            'ride' => new RideResource($ride),
        ]);
    }
}
