<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EvReservationStatus;
use App\Enums\EvStallStatus;
use App\Enums\EvStationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EvStation\ManageStallFormRequest;
use App\Http\Requests\EvStation\StoreEvStationFormRequest;
use App\Http\Requests\EvStation\UpdateEvStationFormRequest;
use App\Http\Resources\EvStationResource;
use App\Models\AuditLog;
use App\Models\EvChargingStall;
use App\Models\EvChargingStation;
use App\Models\EvReservation;
use App\Services\CityDetectionService;
use App\Services\StallAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminEvStationController extends Controller
{
    public function __construct(
        private readonly StallAvailabilityService $stallService,
        private readonly CityDetectionService $cityDetection,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(EvStationStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $stations = EvChargingStation::query()
            ->with(['city', 'stalls'])
            ->withCount(['reservations as queue_length' => fn ($q) => $q->where('status', EvReservationStatus::Queued)])
            ->when($request->query('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $search) => $q->where(
                'name',
                'ilike',
                '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%',
            ))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return response()->json([
            'stations' => EvStationResource::collection($stations),
            'meta' => [
                'current_page' => $stations->currentPage(),
                'last_page' => $stations->lastPage(),
                'per_page' => $stations->perPage(),
                'total' => $stations->total(),
            ],
        ]);
    }

    public function store(StoreEvStationFormRequest $request): JsonResponse
    {
        $attributes = $request->validated();

        if (empty($attributes['city_id'])) {
            $city = $this->cityDetection->detectCity((float) $attributes['lat'], (float) $attributes['lng'])['city'];

            if (! $city) {
                throw ValidationException::withMessages([
                    'city_id' => 'These coordinates are not inside any active city. Choose the city.',
                ]);
            }

            $attributes['city_id'] = $city->id;
        }

        $station = DB::transaction(function () use ($request, $attributes) {
            $station = EvChargingStation::create($attributes);

            for ($i = 1; $i <= $station->total_stalls; $i++) {
                EvChargingStall::create([
                    'station_id' => $station->id,
                    'stall_number' => $i,
                    'status' => EvStallStatus::Available,
                ]);
            }

            AuditLog::record($station, 'ev_station_created', $request->user(), [], $station->toArray());

            return $station->fresh();
        });

        $station->load(['city', 'stalls']);

        return response()->json([
            'message' => 'EV charging station created successfully.',
            'station' => new EvStationResource($station),
        ], 201);
    }

    public function show(EvChargingStation $station): JsonResponse
    {
        $station->load([
            'city',
            'stalls' => fn ($q) => $q->orderBy('stall_number'),
            'stalls.currentDriver.driver.vehicle',
        ]);

        $queue = $station->reservations()
            ->queued()
            ->orderBy('created_at')
            ->with('driver.driver.vehicle')
            ->get()
            ->values()
            ->map(fn (EvReservation $reservation, int $index) => [
                'reservation_id' => $reservation->id,
                'position' => $reservation->queue_position ?? $index + 1,
                'driver' => $reservation->driver ? [
                    'id' => $reservation->driver->id,
                    'name' => trim($reservation->driver->first_name.' '.$reservation->driver->last_name),
                    'phone' => $reservation->driver->phone,
                ] : null,
                'vehicle' => ($vehicle = $reservation->driver?->driver?->vehicle) ? [
                    'make' => $vehicle->make,
                    'model' => $vehicle->model,
                    'colour' => $vehicle->colour,
                    'plate_number' => $vehicle->plate_number,
                ] : null,
                'battery_level' => $reservation->battery_level,
                'joined_at' => $reservation->created_at?->toISOString(),
                'wait_seconds' => $reservation->created_at
                    ? (int) $reservation->created_at->diffInSeconds(now(), absolute: true)
                    : null,
                'estimated_available_at' => $reservation->estimated_available_at?->toISOString(),
            ]);

        return response()->json([
            'station' => new EvStationResource($station),
            'availability' => $this->stallService->getStationAvailability($station),
            // Drivers waiting for a stall, in the order they joined.
            'queue' => $queue,
        ]);
    }

    public function update(UpdateEvStationFormRequest $request, EvChargingStation $station): JsonResponse
    {
        $oldValues = $station->only(array_keys($request->validated()));

        DB::transaction(function () use ($request, $station, $oldValues) {
            $station->update(collect($request->validated())->except('total_stalls')->all());

            if ($request->has('total_stalls')) {
                $this->resizeStalls($station, (int) $request->validated('total_stalls'));
            }

            AuditLog::record(
                $station,
                'ev_station_updated',
                $request->user(),
                $oldValues,
                $station->only(array_keys($request->validated())),
            );
        });

        $station->load(['city', 'stalls']);

        return response()->json([
            'message' => 'EV charging station updated successfully.',
            'station' => new EvStationResource($station),
        ]);
    }

    public function toggleStatus(Request $request, EvChargingStation $station): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:active,inactive,maintenance'],
        ]);

        $oldStatus = $station->status->value;

        DB::transaction(function () use ($request, $station, $oldStatus) {
            $station->update(['status' => $request->input('status')]);

            AuditLog::record(
                $station,
                'ev_station_status_changed',
                $request->user(),
                ['status' => $oldStatus],
                ['status' => $station->status->value],
            );
        });

        $station->load(['city', 'stalls']);

        return response()->json([
            'message' => 'Station status updated successfully.',
            'station' => new EvStationResource($station),
        ]);
    }

    public function manageStalls(ManageStallFormRequest $request, EvChargingStation $station): JsonResponse
    {
        DB::transaction(function () use ($request, $station) {
            foreach ($request->validated('stalls') as $stallData) {
                $station->stalls()->updateOrCreate(
                    ['stall_number' => $stallData['stall_number']],
                    ['status' => $stallData['status'] ?? EvStallStatus::Available->value],
                );
            }

            $station->update(['total_stalls' => $station->stalls()->count()]);

            AuditLog::record($station, 'ev_stalls_managed', $request->user(), [], $request->validated('stalls'));
        });

        $station->load('stalls');

        return response()->json([
            'message' => 'Stalls updated successfully.',
            'station' => new EvStationResource($station),
        ]);
    }

    public function destroy(Request $request, EvChargingStation $station): JsonResponse
    {
        $activeReservations = $station->reservations()->active()->exists();

        if ($activeReservations) {
            return response()->json([
                'message' => 'Cannot delete station with active reservations.',
            ], 422);
        }

        DB::transaction(function () use ($request, $station) {
            AuditLog::record($station, 'ev_station_deleted', $request->user(), $station->toArray());

            $station->stalls()->delete();
            $station->delete();
        });

        return response()->json(['message' => 'EV charging station deleted.']);
    }

    public function utilisation(Request $request): JsonResponse
    {
        $stations = EvChargingStation::query()
            ->with('stalls')
            ->withCount(['reservations as queue_length' => fn ($q) => $q->where('status', EvReservationStatus::Queued)])
            ->when($request->query('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->active()
            ->get();

        $utilisation = $stations->map(function (EvChargingStation $station) {
            $stalls = $station->stalls;
            $totalStalls = $stalls->count();

            $available = $stalls->where('status', EvStallStatus::Available)->count();
            $occupied = $stalls->where('status', EvStallStatus::Occupied)->count();
            $reserved = $stalls->where('status', EvStallStatus::Reserved)->count();
            $outOfService = $stalls->where('status', EvStallStatus::OutOfService)->count();

            $soonestDeparture = $stalls
                ->where('status', EvStallStatus::Occupied)
                ->whereNotNull('estimated_departure_at')
                ->where('estimated_departure_at', '>', now())
                ->sortBy('estimated_departure_at')
                ->first();

            $estimatedWait = $soonestDeparture
                ? (int) now()->diffInMinutes($soonestDeparture->estimated_departure_at, absolute: true)
                : null;

            $availability = [
                'total' => $totalStalls,
                'available' => $available,
                'occupied' => $occupied,
                'reserved' => $reserved,
                'out_of_service' => $outOfService,
                'queue_length' => $station->queue_length,
            ];

            return [
                'station_id' => $station->id,
                'station_name' => $station->name,
                'city_id' => $station->city_id,
                'total_stalls' => $totalStalls,
                'availability' => $availability,
                'occupancy_rate' => $totalStalls > 0
                    ? round(($occupied + $reserved) / $totalStalls * 100, 1)
                    : 0,
                'estimated_wait_minutes' => $estimatedWait,
            ];
        });

        return response()->json([
            'utilisation' => $utilisation,
            'summary' => [
                'total_stations' => $stations->count(),
                'total_stalls' => $utilisation->sum('total_stalls'),
                'total_available' => $utilisation->sum('availability.available'),
                'total_occupied' => $utilisation->sum('availability.occupied'),
                'average_occupancy_rate' => $utilisation->avg('occupancy_rate'),
            ],
        ]);
    }

    /**
     * Add stalls after the highest number, or remove the highest-numbered
     * stalls when they are free and nobody holds a reservation on them.
     */
    private function resizeStalls(EvChargingStation $station, int $target): void
    {
        $stalls = $station->stalls()->orderBy('stall_number')->lockForUpdate()->get();
        $current = $stalls->count();

        if ($target > $current) {
            $next = (int) $stalls->max('stall_number');

            for ($i = 1; $i <= $target - $current; $i++) {
                EvChargingStall::create([
                    'station_id' => $station->id,
                    'stall_number' => $next + $i,
                    'status' => EvStallStatus::Available,
                ]);
            }
        }

        if ($target < $current) {
            $toRemove = $stalls->sortByDesc('stall_number')->take($current - $target);

            $busy = $toRemove->filter(fn (EvChargingStall $stall) => ! in_array($stall->status, [EvStallStatus::Available, EvStallStatus::OutOfService], true)
                || $stall->reservations()->active()->exists());

            if ($busy->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'total_stalls' => 'Stall '.$busy->pluck('stall_number')->sort()->implode(', ').' is in use. Free it before reducing the stall count.',
                ]);
            }

            EvChargingStall::whereIn('id', $toRemove->modelKeys())->delete();
        }

        $station->update(['total_stalls' => $target]);
    }
}
