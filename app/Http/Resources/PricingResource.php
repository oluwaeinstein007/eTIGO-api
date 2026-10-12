<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PricingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'vehicle_class_id' => $this->vehicle_class_id,
            'base_fare' => (float) $this->base_fare,
            'per_km_rate' => (float) $this->per_km_rate,
            'per_minute_rate' => (float) $this->per_minute_rate,
            'minimum_fare' => (float) $this->minimum_fare,
            'waiting_time_rate' => (float) ($this->waiting_time_rate ?? 0),
            'free_waiting_minutes' => $this->free_waiting_minutes,
            'is_current' => (bool) ($this->is_current ?? false),
            'version' => $this->version,
            'effective_from' => $this->effective_from,
            'effective_until' => $this->effective_until ?? null,
            'city' => new CityResource($this->whenLoaded('city')),
            'vehicle_class' => $this->when($this->relationLoaded('vehicleClass'), fn () => [
                'id' => $this->vehicleClass->id,
                'name' => $this->vehicleClass->name,
            ]),
            'created_by' => new UserResource($this->whenLoaded('createdByAdmin')),
            'created_at' => $this->created_at,
        ];
    }
}
