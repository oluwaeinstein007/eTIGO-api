<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
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

    public function store(StoreReservationFormRequest $request, EvChargingStation $station): JsonResponse
    {
        try {
            $result = $this->reservationService->reserve($station, $request->user());
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
            ->with(['station', 'stall'])
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

        $reservation->load(['station', 'stall']);

        return response()->json([
            'reservation' => new EvReservationResource($reservation),
        ]);
    }

    public function activate(EvReservation $reservation): JsonResponse
    {
        if ($reservation->driver_id !== request()->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        try {
            $reservation = $this->reservationService->activate($reservation);
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
