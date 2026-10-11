<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OfflineTripFlag */
class OfflineFlagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'ride_id' => $this->ride_id,
            'driver_id' => $this->driver_id,
            'passenger_id' => $this->passenger_id,
            'sanction_tier' => [
                'value' => $this->sanction_tier->value,
                'label' => $this->sanction_tier->label(),
            ],
            'sanction_action' => $this->sanction_action,
            'is_disputed' => $this->is_disputed,
            'dispute_outcome' => $this->when($this->dispute_outcome, fn () => [
                'value' => $this->dispute_outcome->value,
                'label' => $this->dispute_outcome->label(),
            ]),
            'dispute_notes' => $this->when($this->is_disputed, $this->dispute_notes),
            'detection_data' => $this->when($isAdmin, $this->detection_data),
            'flagged_at' => $this->flagged_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'passenger' => new UserResource($this->whenLoaded('passenger')),
            'ride' => new RideResource($this->whenLoaded('ride')),
            'resolved_by' => new UserResource($this->whenLoaded('resolvedBy')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
