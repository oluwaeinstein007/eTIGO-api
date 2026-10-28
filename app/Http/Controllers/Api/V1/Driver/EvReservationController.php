<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Enums\EvReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EvStation\StoreChargeRequestFormRequest;
use App\Http\Requests\EvStation\StoreReservationFormRequest;
use App\Http\Resources\EvReservationResource;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    public function storeRequest(StoreChargeRequestFormRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        try {
            $this->reservationService->assertCanCharge($user);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Same rule as a stall reservation: one open request or reservation at a time.
        if (EvReservation::forDriver($user->id)->active()->count() >= (int) config('ev_charging.max_active_reservations_per_driver', 1)) {
            return response()->json(['message' => 'You already have an active reservation.'], 422);
        }

        $stationId = $validated['station_id'] ?? null;
        if (! $stationId) {
            $station = EvChargingStation::query()
                ->when($user->driver?->city_id, fn ($q, $cityId) => $q->where('city_id', $cityId))
                ->where('status', 'active')
                ->first() ?? EvChargingStation::where('status', 'active')->first();
            $stationId = $station?->id;
        }

        $reservation = EvReservation::create([
            'driver_id' => $user->id,
            'station_id' => $stationId,
            'status' => EvReservationStatus::PendingReview,
            'type' => 'charging',
            'battery_level' => $validated['battery_level'],
            'requested_date' => $validated['requested_date'] ?? now('Africa/Lagos')->toDateString(),
            'requested_time' => $validated['requested_time'] ?? now('Africa/Lagos')->format('h:i a'),
            'notes' => $validated['notes'] ?? null,
            'estimated_charge_minutes' => $validated['estimated_charge_minutes'] ?? null,
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservation->load(['station', 'stall', 'driver.driver.vehicle']);

        return response()->json([
            'message' => 'Charge request submitted successfully.',
            'reservation' => new EvReservationResource($reservation),
        ], 201);
    }

    public function store(StoreReservationFormRequest $request, EvChargingStation $station): JsonResponse
    {
        try {
            $result = $this->reservationService->reserve(
                $station,
                $request->user(),
                $request->validated('estimated_charge_minutes'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['outcome'] === 'retry') {
            return response()->json([
                'message' => $result['message'],
                'outcome' => 'retry',
                'retry_after_minutes' => $result['retry_after_minutes'],
                'estimated_wait_minutes' => $result['estimated_wait_minutes'],
            ], 200);
        }

        $statusCode = $result['outcome'] === 'reserved' ? 201 : 200;

        $response = [
            'message' => $result['outcome'] === 'reserved'
                ? 'Stall reserved successfully.'
                : 'Added to queue.',
            'outcome' => $result['outcome'],
            'reservation' => new EvReservationResource($result['reservation']),
        ];

        if ($result['outcome'] === 'queued') {
            $response['queue_position'] = $result['queue_position'];
            $response['estimated_available_at'] = $result['estimated_available_at']?->toISOString();
        }

        return response()->json($response, $statusCode);
    }

    public function index(Request $request): JsonResponse
    {
        $reservations = EvReservation::query()
            ->with(['station', 'stall', 'driver.driver.vehicle'])
            ->forDriver($request->user()->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'reservations' => EvReservationResource::collection($reservations),
            'meta' => [
                'current_page' => $reservations->currentPage(),
                'last_page' => $reservations->lastPage(),
                'per_page' => $reservations->perPage(),
                'total' => $reservations->total(),
            ],
        ]);
    }

    public function show(EvReservation $reservation): JsonResponse
    {
        if ($reservation->driver_id !== request()->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $reservation->load(['station', 'stall', 'driver.driver.vehicle']);

        return response()->json([
            'reservation' => new EvReservationResource($reservation),
        ]);
    }

    public function activate(Request $request, EvReservation $reservation): JsonResponse
    {
        if ($reservation->driver_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            // Optional at the stall: replaces the estimate given when reserving.
            'estimated_charge_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
        ]);

        try {
            $reservation = $this->reservationService->activate($reservation, $validated['estimated_charge_minutes'] ?? $reservation->estimated_charge_minutes);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $reservation->load(['station', 'stall']);

        return response()->json([
            'message' => 'Reservation activated. Charging session started.',
            'reservation' => new EvReservationResource($reservation),
        ]);
    }

    public function complete(EvReservation $reservation): JsonResponse
    {
        if ($reservation->driver_id !== request()->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try {
            $reservation = $this->reservationService->complete($reservation);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $reservation->load(['station', 'stall']);

        return response()->json([
            'message' => 'Charging session completed.',
            'reservation' => new EvReservationResource($reservation),
        ]);
    }

    public function cancel(EvReservation $reservation): JsonResponse
    {
        if ($reservation->driver_id !== request()->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try {
            $reservation = $this->reservationService->cancel($reservation);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $reservation->load(['station', 'stall']);

        return response()->json([
            'message' => 'Reservation cancelled.',
            'reservation' => new EvReservationResource($reservation),
        ]);
    }
}
