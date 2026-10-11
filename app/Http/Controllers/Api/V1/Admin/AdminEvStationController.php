<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EvStallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EvStation\ManageStallFormRequest;
use App\Http\Requests\EvStation\StoreEvStationFormRequest;
use App\Http\Requests\EvStation\UpdateEvStationFormRequest;
use App\Http\Resources\EvStationResource;
use App\Models\AuditLog;
use App\Models\EvChargingStation;
use App\Models\EvChargingStall;
use App\Services\StallAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEvStationController extends Controller
{
    public function __construct(
        private readonly StallAvailabilityService $stallService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $stations = EvChargingStation::query()
            ->with(['city', 'stalls'])
            ->when($request->query('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $search) => $q->where('name', 'ilike', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

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
        $station = DB::transaction(function () use ($request) {
            $station = EvChargingStation::create($request->validated());

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
        $station->load(['city', 'stalls.currentDriver']);

        return response()->json([
            'station' => new EvStationResource($station),
            'availability' => $this->stallService->getStationAvailability($station),
        ]);
    }

    public function update(UpdateEvStationFormRequest $request, EvChargingStation $station): JsonResponse
    {
        $oldValues = $station->only(array_keys($request->validated()));

        DB::transaction(function () use ($request, $station) {
            $station->update($request->validated());

            AuditLog::record(
                $station,
                'ev_station_updated',
                $request->user(),
                $station->getOriginal(),
                $station->getAttributes(),
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

        DB::transaction(function () use ($request, $station) {
            $station->update(['status' => $request->input('status')]);

            AuditLog::record(
                $station,
                'ev_station_status_changed',
                $request->user(),
                ['status' => $station->getOriginal('status')],
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

            AuditLog::record($station, 'ev_stalls_managed', $request->user(), [], $stallData ?? []);
        });

        $station->load('stalls');

        return response()->json([
            'message' => 'Stalls updated successfully.',
            'station' => new EvStationResource($station),
        ]);
    }

    public function utilisation(Request $request): JsonResponse
    {
        $stations = EvChargingStation::query()
            ->with('stalls')
            ->when($request->query('city_id'), fn ($q, $cityId) => $q->where('city_id', $cityId))
            ->active()
            ->get();

        $utilisation = $stations->map(function (EvChargingStation $station) {
            $availability = $this->stallService->getStationAvailability($station);

            return [
                'station_id' => $station->id,
                'station_name' => $station->name,
                'city_id' => $station->city_id,
                'total_stalls' => $station->total_stalls,
                'availability' => $availability,
                'occupancy_rate' => $station->total_stalls > 0
                    ? round(($availability['occupied'] + $availability['reserved']) / $station->total_stalls * 100, 1)
                    : 0,
                'estimated_wait_minutes' => $this->stallService->getEstimatedWaitMinutes($station),
            ];
        });

        return response()->json([
            'utilisation' => $utilisation,
            'summary' => [
                'total_stations' => $stations->count(),
                'total_stalls' => $stations->sum('total_stalls'),
                'total_available' => $utilisation->sum('availability.available'),
                'total_occupied' => $utilisation->sum('availability.occupied'),
                'average_occupancy_rate' => $utilisation->avg('occupancy_rate'),
            ],
        ]);
    }
}
