<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EvReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EvStation\ConfirmChargeRequestFormRequest;
use App\Http\Requests\EvStation\DeclineChargeRequestFormRequest;
use App\Http\Resources\EvReservationResource;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Services\EvChargeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The operations queue for charge requests drivers submit from the app.
 */
class AdminEvChargeRequestController extends Controller
{
    public function __construct(
        private readonly EvChargeRequestService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'station_id' => ['nullable', 'uuid'],
            'city_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $statuses = $request->filled('status')
            ? array_filter(array_map('trim', explode(',', (string) $request->query('status'))))
            : [EvReservationStatus::PendingReview->value, EvReservationStatus::Confirmed->value];

        foreach ($statuses as $status) {
            if (! EvReservationStatus::tryFrom($status)) {
                return response()->json([
                    'message' => "Unknown status \"{$status}\".",
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => ['status' => ["Unknown status \"{$status}\"."]],
                ], 422);
            }
        }

        $search = $request->query('search');

        $requests = EvReservation::query()
            ->chargeRequests()
            ->with(['station', 'stall', 'driver.driver.vehicle', 'reviewedBy'])
            ->whereIn('status', $statuses)
            ->when($request->query('station_id'), fn ($q, $id) => $q->where('station_id', $id))
            ->when($request->query('city_id'), fn ($q, $id) => $q->whereHas('station', fn ($s) => $s->where('city_id', $id)))
            ->when($search, function ($q, $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $q->whereHas('driver', fn ($d) => $d->where(fn ($d) => $d
                    ->where('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)));
            })
            // Waiting requests first, oldest first; then upcoming slots, soonest first.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [EvReservationStatus::PendingReview->value])
            ->orderByRaw('COALESCE(confirmed_slot_at, created_at) ASC')
            ->paginate($this->perPage($request));

        return response()->json([
            'charge_requests' => EvReservationResource::collection($requests),
            'summary' => [
                'pending_review' => EvReservation::chargeRequests()->where('status', EvReservationStatus::PendingReview)->count(),
                'confirmed' => EvReservation::chargeRequests()->where('status', EvReservationStatus::Confirmed)->count(),
            ],
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function confirm(ConfirmChargeRequestFormRequest $request, EvReservation $reservation): JsonResponse
    {
        $station = $request->filled('station_id')
            ? EvChargingStation::findOrFail($request->validated('station_id'))
            : null;

        try {
            $reservation = $this->service->confirm(
                $reservation,
                $request->user(),
                Carbon::parse($request->validated('confirmed_slot_at')),
                $station,
            );
        } catch (\RuntimeException $e) {
            return $this->refused($e, $reservation);
        }

        return response()->json([
            'message' => 'Charge request confirmed. The driver has been told.',
            'charge_request' => new EvReservationResource($reservation->load(['station', 'stall', 'driver.driver.vehicle', 'reviewedBy'])),
        ]);
    }

    public function decline(DeclineChargeRequestFormRequest $request, EvReservation $reservation): JsonResponse
    {
        try {
            $reservation = $this->service->decline($reservation, $request->user(), $request->validated('reason'));
        } catch (\RuntimeException $e) {
            return $this->refused($e, $reservation);
        }

        return response()->json([
            'message' => 'Charge request declined. The driver has been told.',
            'charge_request' => new EvReservationResource($reservation->load(['station', 'stall', 'driver.driver.vehicle', 'reviewedBy'])),
        ]);
    }

    private function refused(\RuntimeException $e, EvReservation $reservation): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'error_code' => 'VALIDATION_ERROR',
            'current_status' => $reservation->fresh()?->status->value,
        ], 422);
    }
}
