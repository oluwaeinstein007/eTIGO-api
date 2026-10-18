<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverScheduledRideResource;
use App\Models\Ride;
use App\Services\DriverShiftService;
use App\Services\ScheduledRideReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Drivers reserving scheduled rides in advance (Uber Reserve model).
 */
class DriverScheduledRideController extends Controller
{
    public function __construct(
        private ScheduledRideReservationService $reservationService,
    ) {}

    /**
     * The driver's upcoming reservations, soonest first.
     */
    public function index(Request $request): JsonResponse
    {
        $rides = $this->reservationService->reservationsOf($request->user())
            ->with(['scheduledRide', 'vehicleClass', 'passenger'])
            ->paginate($this->perPage($request, 15, 50));

        return $this->listResponse($rides);
    }

    /**
     * Bookings the driver could reserve: their city and vehicle class, not
     * yet reserved, matching not started. Soonest first.
     */
    public function available(Request $request): JsonResponse
    {
        $driver = $request->user()->driver()->with('vehicle')->first();

        // A shared car's driver may not hold the car right now; their
        // shifts decide what they can reserve.
        if ($driver === null || ($driver->vehicle === null && ! app(DriverShiftService::class)->isShiftDriver($driver))) {
            return response()->json(['rides' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 0, 'total' => 0]]);
        }

        $rides = $this->reservationService->availableFor($driver)
            ->with(['scheduledRide', 'vehicleClass', 'passenger'])
            ->paginate($this->perPage($request, 15, 50));

        return $this->listResponse($rides);
    }

    public function reserve(Request $request, Ride $ride): JsonResponse
    {
        $ride->load('scheduledRide');
        $ride = $this->reservationService->reserve($ride, $request->user());
        $ride->load(['scheduledRide', 'vehicleClass', 'passenger']);

        return response()->json([
            'message' => 'Ride reserved. Be online by the dispatch time to take it.',
            'ride' => new DriverScheduledRideResource($ride),
        ]);
    }

    public function release(Request $request, Ride $ride): JsonResponse
    {
        $ride = $this->reservationService->release($ride, $request->user());
        $ride->load(['scheduledRide', 'vehicleClass']);

        return response()->json([
            'message' => 'Reservation released.',
            'ride' => new DriverScheduledRideResource($ride),
        ]);
    }

    private function listResponse(LengthAwarePaginator $rides): JsonResponse
    {
        return response()->json([
            'rides' => DriverScheduledRideResource::collection($rides),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }
}
