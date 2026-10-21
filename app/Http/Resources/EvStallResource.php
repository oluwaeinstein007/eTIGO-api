<?php

namespace App\Http\Resources;

use App\Models\EvChargingStall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EvChargingStall */
class EvStallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'stall_number' => $this->stall_number,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'occupied_since' => $this->occupied_since?->toISOString(),
            'estimated_departure_at' => $this->estimated_departure_at?->toISOString(),
            'current_driver' => $this->when(
                $isAdmin && $this->relationLoaded('currentDriver'),
                fn () => $this->currentDriver ? new EvDriverResource($this->currentDriver) : null,
            ),
            'station' => new EvStationResource($this->whenLoaded('station')),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
