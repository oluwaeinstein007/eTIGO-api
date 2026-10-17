<?php

namespace App\Http\Resources;

use App\Enums\EvStallStatus;
use App\Models\EvChargingStation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EvChargingStation */
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
            'connector_types' => $this->connector_types ?? [],
            'power_output_kw' => $this->power_output_kw,
            // Kobo per kWh.
            'pricing_per_kwh' => $this->pricing_per_kwh,
            'operating_hours' => $this->operating_hours,
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
            'total' => $stalls->count(),
            'available' => $stalls->where('status', EvStallStatus::Available)->count(),
            'occupied' => $stalls->where('status', EvStallStatus::Occupied)->count(),
            'reserved' => $stalls->where('status', EvStallStatus::Reserved)->count(),
            'out_of_service' => $stalls->where('status', EvStallStatus::OutOfService)->count(),
            // Present when the query counted the queue (withCount).
            'queue_length' => $this->when(isset($this->queue_length), fn () => (int) $this->queue_length),
        ];
    }
}
