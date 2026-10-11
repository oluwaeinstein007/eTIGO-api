<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EvReservation */
class EvReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'station_id' => $this->station_id,
            'stall_id' => $this->stall_id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'queue_position' => $this->queue_position,
            'estimated_available_at' => $this->estimated_available_at?->toISOString(),
            'fee_amount' => $this->fee_amount,
            'fee_waived' => $this->fee_waived,
            'reserved_at' => $this->reserved_at?->toISOString(),
            'activated_at' => $this->activated_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'station' => new EvStationResource($this->whenLoaded('station')),
            'stall' => new EvStallResource($this->whenLoaded('stall')),
            'driver' => new UserResource($this->whenLoaded('driver')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
