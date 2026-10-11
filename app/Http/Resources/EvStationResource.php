<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\EvChargingStation */
class EvStationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'city_id' => $this->city_id,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'address' => $this->address,
            'total_stalls' => $this->total_stalls,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'availability' => $this->when(
                $this->relationLoaded('stalls'),
                fn () => $this->getAvailabilitySummary(),
            ),
            'city' => new CityResource($this->whenLoaded('city')),
            'stalls' => EvStallResource::collection($this->whenLoaded('stalls')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function getAvailabilitySummary(): array
    {
        $stalls = $this->stalls;

        return [
            'available' => $stalls->where('status.value', 'available')->count(),
            'occupied' => $stalls->where('status.value', 'occupied')->count(),
            'reserved' => $stalls->where('status.value', 'reserved')->count(),
            'out_of_service' => $stalls->where('status.value', 'out_of_service')->count(),
        ];
    }
}
