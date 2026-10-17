<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SosIncidentStatus;
use App\Enums\SosQueueStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sos\DispatchSosFormRequest;
use App\Http\Requests\Sos\LogSosCallFormRequest;
use App\Http\Requests\Sos\ResolveSosFormRequest;
use App\Http\Resources\SosIncidentResource;
use App\Models\AuditLog;
use App\Models\SosIncident;
use App\Services\DriverLocationService;
use App\Services\RideRouteService;
use App\Services\SosService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminSosController extends Controller
{
    private const LIST_RELATIONS = ['triggeredBy', 'operator', 'ride.passenger', 'ride.driver', 'ride.vehicleClass'];

    public function __construct(
        private readonly SosService $sosService,
        private readonly RideRouteService $routeService,
        private readonly DriverLocationService $locationService,
    ) {}

    public function active(Request $request): JsonResponse
    {
        $this->validateListFilters($request);

        $incidents = SosIncident::active()
            ->with(self::LIST_RELATIONS)
            ->when($request->query('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->query('queue_status'), function ($query, $queueStatus) {
                $query->withQueueStatus(SosQueueStatus::from($queueStatus));
            })
            ->when($request->query('search'), fn ($query, $search) => $query->search($search))
            ->orderByRaw("CASE
                WHEN status = 'escalated' THEN 1
                WHEN status = 'triggered' THEN 2
                WHEN status = 'check_in_sent' THEN 3
                WHEN status = 'operator_assigned' THEN 4
                WHEN status = 'dispatched' THEN 5
                ELSE 6
            END")
            ->orderBy('created_at', 'asc')
            ->paginate($this->perPage($request));

        return response()->json([
            'message' => 'Active SOS incidents retrieved.',
            'incidents' => SosIncidentResource::collection($incidents),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'last_page' => $incidents->lastPage(),
                'per_page' => $incidents->perPage(),
                'total' => $incidents->total(),
            ],
        ]);
    }

    public function show(SosIncident $incident): JsonResponse
    {
        $incident->load([
            'triggeredBy',
            'operator',
            'ride.passenger',
            'ride.driver',
            'ride.vehicleClass',
            'ride.city',
            'eventLogs.actor',
        ]);

        $ride = $incident->ride;
        $driverLocation = $ride?->driver_id && $incident->isActive()
            ? rescue(fn () => $this->locationService->getDriverLocation($ride->driver_id), report: false)
            : null;

        return response()->json([
            'message' => 'SOS incident details retrieved.',
            'incident' => new SosIncidentResource($incident),
            // The ride's planned route for the map; null when routing fails.
            'route' => $ride ? $this->routeService->getPlannedRoute($ride) : null,
            // Last known position of the driver's phone while the incident is
            // open; live updates arrive as driver.location.updated on admin.rides.
            'driver_location' => $driverLocation ? [
                'lat' => $driverLocation['lat'],
                'lng' => $driverLocation['lng'],
                'heading' => $driverLocation['heading'],
                'speed' => $driverLocation['speed'],
                'updated_at' => $driverLocation['timestamp']
                    ? Carbon::createFromTimestamp($driverLocation['timestamp'])->toISOString()
                    : null,
            ] : null,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->validateListFilters($request);

        $incidents = SosIncident::query()
            ->with(self::LIST_RELATIONS)
            ->when($request->query('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->query('queue_status'), function ($query, $queueStatus) {
                $query->withQueueStatus(SosQueueStatus::from($queueStatus));
            })
            ->when($request->query('search'), fn ($query, $search) => $query->search($search))
            ->when($request->query('trigger_type'), function ($query, $type) {
                $query->where('trigger_type', $type);
            })
            ->when($request->query('ride_id'), function ($query, $rideId) {
                $query->where('ride_id', $rideId);
            })
            ->when($request->query('date_from'), function ($query, $date) {
                $query->where('created_at', '>=', $date);
            })
            ->when($request->query('date_to'), function ($query, $date) {
                $query->where('created_at', '<=', $date);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($this->perPage($request));

        return response()->json([
            'message' => 'SOS incident history retrieved.',
            'incidents' => SosIncidentResource::collection($incidents),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'last_page' => $incidents->lastPage(),
                'per_page' => $incidents->perPage(),
                'total' => $incidents->total(),
            ],
        ]);
    }

    public function assign(SosIncident $incident): JsonResponse
    {
        $operator = request()->user();

        try {
            $incident = $this->sosService->assignOperator($incident, $operator);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $incident->load(self::LIST_RELATIONS);

        AuditLog::record($incident, 'sos_operator_assigned', $operator, null, [
            'operator_id' => $operator->id,
        ]);

        return response()->json([
            'message' => 'You have been assigned to this incident.',
            'incident' => new SosIncidentResource($incident),
        ]);
    }

    public function dispatch(DispatchSosFormRequest $request, SosIncident $incident): JsonResponse
    {
        $operator = $request->user();

        try {
            $incident = $this->sosService->dispatch($incident, $operator, $request->validated());
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $incident->load(self::LIST_RELATIONS);

        AuditLog::record($incident, 'sos_emergency_dispatched', $operator, null, [
            'emergency_service_type' => $request->validated('emergency_service_type'),
        ]);

        return response()->json([
            'message' => 'Emergency services dispatch initiated.',
            'incident' => new SosIncidentResource($incident),
        ]);
    }

    public function resolve(ResolveSosFormRequest $request, SosIncident $incident): JsonResponse
    {
        $operator = $request->user();

        try {
            $incident = $this->sosService->resolve(
                $incident,
                $operator,
                $request->validated('notes'),
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $incident->load(self::LIST_RELATIONS);

        AuditLog::record($incident, 'sos_resolved', $operator, null, [
            'resolution_notes' => $request->validated('notes'),
        ]);

        return response()->json([
            'message' => 'SOS incident resolved.',
            'incident' => new SosIncidentResource($incident),
        ]);
    }

    /**
     * Log a call the operator made about the incident (placed from their own
     * phone) so it appears in the incident's audit trail.
     */
    public function logCall(LogSosCallFormRequest $request, SosIncident $incident): JsonResponse
    {
        $incident = $this->sosService->logCall($incident, $request->user(), [
            'called_party' => $request->validated('called_party', 'passenger'),
            'outcome' => $request->validated('outcome'),
            'phone' => $request->validated('phone'),
            'notes' => $request->validated('notes'),
        ]);

        $incident->load([...self::LIST_RELATIONS, 'eventLogs.actor']);

        return response()->json([
            'message' => 'Call logged.',
            'incident' => new SosIncidentResource($incident),
        ], 201);
    }

    private function validateListFilters(Request $request): void
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(SosIncidentStatus::class)],
            'queue_status' => ['nullable', Rule::enum(SosQueueStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
