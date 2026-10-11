<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sos\DispatchSosFormRequest;
use App\Http\Requests\Sos\ResolveSosFormRequest;
use App\Http\Resources\SosIncidentResource;
use App\Models\AuditLog;
use App\Models\SosIncident;
use App\Services\SosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSosController extends Controller
{
    public function __construct(
        private readonly SosService $sosService,
    ) {}

    public function active(Request $request): JsonResponse
    {
        $incidents = SosIncident::active()
            ->with(['triggeredBy', 'operator', 'ride.passenger', 'ride.driver', 'ride.vehicleClass'])
            ->when($request->query('status'), function ($query, $status) {
                $query->where('status', $status);
            })
            ->orderByRaw("CASE
                WHEN status = 'escalated' THEN 1
                WHEN status = 'triggered' THEN 2
                WHEN status = 'check_in_sent' THEN 3
                WHEN status = 'operator_assigned' THEN 4
                WHEN status = 'dispatched' THEN 5
                ELSE 6
            END")
            ->orderBy('created_at', 'asc')
            ->paginate($request->integer('per_page', 20));

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

        return response()->json([
            'message' => 'SOS incident details retrieved.',
            'incident' => new SosIncidentResource($incident),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $incidents = SosIncident::query()
            ->with(['triggeredBy', 'operator', 'ride'])
            ->when($request->query('status'), function ($query, $status) {
                $query->where('status', $status);
            })
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
            ->paginate($request->integer('per_page', 20));

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

        $incident->load(['triggeredBy', 'operator', 'ride.passenger', 'ride.driver']);

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

        $incident->load(['triggeredBy', 'operator']);

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

        $incident->load(['triggeredBy', 'operator']);

        AuditLog::record($incident, 'sos_resolved', $operator, null, [
            'resolution_notes' => $request->validated('notes'),
        ]);

        return response()->json([
            'message' => 'SOS incident resolved.',
            'incident' => new SosIncidentResource($incident),
        ]);
    }
}
