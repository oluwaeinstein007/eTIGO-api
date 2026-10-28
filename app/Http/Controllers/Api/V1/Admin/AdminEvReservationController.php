<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EvReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EvReservationResource;
use App\Models\EvReservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every EV charging request and reservation, reviewed or reserved straight
 * from the station list: search, history, and the operations actions that
 * cancel a reservation or end a session the driver did not finish.
 */
class AdminEvReservationController extends Controller
{
    private const TIMEZONE = 'Africa/Lagos';

    private const RELATIONS = ['station', 'stall', 'driver.driver.vehicle', 'reviewedBy', 'endedBy'];

    public function __construct(
        private readonly ReservationService $reservations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'kind' => ['nullable', 'in:request,instant'],
            'station_id' => ['nullable', 'uuid'],
            'city_id' => ['nullable', 'uuid'],
            'driver_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) ($validated['status'] ?? '')))));

        foreach ($statuses as $status) {
            if (! EvReservationStatus::tryFrom($status)) {
                return response()->json([
                    'message' => "Unknown status \"{$status}\".",
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => ['status' => ["Unknown status \"{$status}\"."]],
                ], 422);
            }
        }

        $reservations = EvReservation::query()
            ->with(self::RELATIONS)
            ->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->when(($validated['kind'] ?? null) === 'request', fn ($q) => $q->whereNotNull('requested_date'))
            ->when(($validated['kind'] ?? null) === 'instant', fn ($q) => $q->whereNull('requested_date'))
            ->when($validated['station_id'] ?? null, fn ($q, $id) => $q->where('station_id', $id))
            ->when($validated['city_id'] ?? null, fn ($q, $id) => $q->whereHas('station', fn ($s) => $s->where('city_id', $id)))
            ->when($validated['driver_id'] ?? null, fn ($q, $id) => $q->where('driver_id', $id))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('reserved_at', '>=', Carbon::parse($from, self::TIMEZONE)->startOfDay()))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('reserved_at', '<=', Carbon::parse($to, self::TIMEZONE)->endOfDay()))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

                $q->whereHas('driver', fn ($d) => $d->where(fn ($d) => $d
                    ->where('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhereHas('driver.vehicle', fn ($v) => $v->where('plate_number', 'ilike', $like))));
            })
            // reserved_at is set on every reservation (a request's submit
            // time, then when its stall was held); newest activity first.
            ->orderByDesc('reserved_at')
            ->paginate($this->perPage($request));

        $open = [
            EvReservationStatus::PendingReview,
            EvReservationStatus::Confirmed,
            EvReservationStatus::Reserved,
            EvReservationStatus::Queued,
            EvReservationStatus::Active,
        ];
        $counts = EvReservation::query()
            ->whereIn('status', $open)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'reservations' => EvReservationResource::collection($reservations),
            // Open reservations right now, across every station.
            'summary' => collect($open)->mapWithKeys(fn (EvReservationStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])->all(),
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
        return response()->json([
            'reservation' => new EvReservationResource($reservation->load(self::RELATIONS)),
        ]);
    }

    public function cancel(Request $request, EvReservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            // Shown to the driver.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            // Give the reservation fee back (not the driver's fault).
            'refund_fee' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->reservations->cancelByOperations($reservation, $request->user(), $validated['reason'], $request->boolean('refund_fee'));
        } catch (\RuntimeException $e) {
            return $this->refused($e, $reservation);
        }

        return response()->json([
            'message' => 'Reservation cancelled. The driver has been told.',
            'fee_refunded' => $result['fee_refunded'],
            'reservation' => new EvReservationResource($result['reservation']->load(self::RELATIONS)),
        ]);
    }

    public function end(Request $request, EvReservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $reservation = $this->reservations->endByOperations($reservation, $request->user(), $validated['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->refused($e, $reservation);
        }

        return response()->json([
            'message' => 'Charging session ended and the stall released. The driver has been told.',
            'reservation' => new EvReservationResource($reservation->load(self::RELATIONS)),
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
