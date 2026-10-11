<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sos\TriggerSosFormRequest;
use App\Http\Resources\SosIncidentResource;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\SosIncident;
use App\Services\SosService;
use Illuminate\Http\JsonResponse;

class SosController extends Controller
{
    public function __construct(
        private readonly SosService $sosService,
    ) {}

    public function trigger(TriggerSosFormRequest $request, Ride $ride): JsonResponse
    {
        $user = $request->user();

        try {
            $incident = $this->sosService->trigger($ride, $user, $request->validated());
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        $incident->load('triggeredBy');

        AuditLog::record($ride, 'sos_triggered', $user, null, [
            'incident_id' => $incident->id,
            'trigger_type' => $incident->trigger_type->value,
        ]);

        return response()->json([
            'message' => 'SOS triggered. A check-in will be sent shortly.',
            'incident' => new SosIncidentResource($incident),
        ], 201);
    }

    public function acknowledge(SosIncident $incident): JsonResponse
    {
        $user = request()->user();

        try {
            $incident = $this->sosService->acknowledge($incident, $user);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $incident->load('triggeredBy');

        return response()->json([
            'message' => 'Check-in acknowledged. Glad you are safe.',
            'incident' => new SosIncidentResource($incident),
        ]);
    }

    public function cancel(SosIncident $incident): JsonResponse
    {
        $user = request()->user();

        try {
            $incident = $this->sosService->cancel($incident, $user);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $incident->load('triggeredBy');

        AuditLog::record($incident, 'sos_cancelled', $user);

        return response()->json([
            'message' => 'SOS incident cancelled.',
            'incident' => new SosIncidentResource($incident),
        ]);
    }
}
