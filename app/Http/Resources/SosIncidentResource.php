<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SosIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'triggered_by_user_id' => $this->triggered_by_user_id,
            'trigger_type' => $this->trigger_type->value,
            'trigger_type_label' => $this->trigger_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'gps_lat' => $this->gps_lat,
            'gps_lng' => $this->gps_lng,
            'vehicle_details' => $this->when($isAdmin, fn () => $this->vehicle_details),
            'telemetry_data' => $this->when($isAdmin, fn () => $this->telemetry_data),
            'check_in_sent_at' => $this->check_in_sent_at?->toISOString(),
            'check_in_acknowledged_at' => $this->check_in_acknowledged_at?->toISOString(),
            'escalated_at' => $this->escalated_at?->toISOString(),
            'operator_id' => $this->when($isAdmin, fn () => $this->operator_id),
            'operator_notes' => $this->when($isAdmin, fn () => $this->operator_notes),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'triggered_by' => new UserResource($this->whenLoaded('triggeredBy')),
            'operator' => $this->when($isAdmin, fn () => new UserResource($this->whenLoaded('operator'))),
            'ride' => new RideResource($this->whenLoaded('ride')),
            'event_logs' => $this->when(
                $isAdmin && $this->relationLoaded('eventLogs'),
                fn () => SosEventLogResource::collection($this->eventLogs),
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
