<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DriverShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverShiftResource;
use App\Http\Resources\VehicleResource;
use App\Models\DriverShift;
use App\Models\Vehicle;
use App\Services\DriverShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Operations run the timetable of shared fleet cars (OQ-20): turn shift
 * mode on per car, assign shifts (or a daily rota), move, shorten, extend
 * and cancel them.
 */
class AdminDriverShiftController extends Controller
{
    public function __construct(
        private DriverShiftService $shifts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'uuid'],
            'driver_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after:from'],
        ]);

        $statuses = isset($validated['status'])
            ? array_values(array_filter(array_map(fn ($s) => DriverShiftStatus::tryFrom(trim($s)), explode(',', $validated['status']))))
            : [];

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : now()->subDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now()->addDays(14);

        $shifts = DriverShift::query()
            ->with(['driver.user', 'vehicle'])
            ->when($validated['vehicle_id'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($validated['driver_id'] ?? null, fn ($q, $id) => $q->where('driver_id', $id))
            ->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->overlapping($from, $to)
            ->orderBy('starts_at')
            ->paginate(50);

        return response()->json([
            'shifts' => DriverShiftResource::collection($shifts),
            'summary' => DriverShift::query()
                ->overlapping($from, $to)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'uuid', Rule::exists('drivers', 'id')],
            'vehicle_id' => ['required', 'uuid', Rule::exists('vehicles', 'id')],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:500'],
            // The same shift on each of the next N days (a rota).
            'repeat_daily_for' => ['sometimes', 'integer', 'min:0', 'max:13'],
        ]);

        $shifts = $this->shifts->create($request->user(), $validated);

        return response()->json([
            'message' => $shifts->count() === 1 ? 'Shift created.' : "{$shifts->count()} shifts created.",
            'shifts' => DriverShiftResource::collection($shifts->each->load(['driver.user', 'vehicle'])),
        ], 201);
    }

    public function show(DriverShift $shift): JsonResponse
    {
        return response()->json([
            'shift' => new DriverShiftResource($shift->load(['driver.user', 'vehicle'])),
        ]);
    }

    public function update(Request $request, DriverShift $shift): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['sometimes', 'uuid', Rule::exists('drivers', 'id')],
            'vehicle_id' => ['sometimes', 'uuid', Rule::exists('vehicles', 'id')],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'Nothing to change.'], 422);
        }

        $shift = $this->shifts->update($shift, $request->user(), $validated);

        return response()->json([
            'message' => 'Shift updated.',
            'shift' => new DriverShiftResource($shift->load(['driver.user', 'vehicle'])),
        ]);
    }

    public function cancel(Request $request, DriverShift $shift): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $shift = $this->shifts->cancel($shift, $request->user(), $validated['reason']);

        return response()->json([
            'message' => 'Shift cancelled.',
            'shift' => new DriverShiftResource($shift->load(['driver.user', 'vehicle'])),
        ]);
    }

    public function shiftMode(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'shift_mode' => ['required', 'boolean'],
        ]);

        $vehicle = $this->shifts->setShiftMode($vehicle, (bool) $validated['shift_mode'], $request->user());

        return response()->json([
            'message' => $vehicle->shift_mode ? 'Shift mode is on: drivers share this car on shifts.' : 'Shift mode is off.',
            'vehicle' => new VehicleResource($vehicle->load(['driver.user', 'vehicleClass'])),
        ]);
    }
}
