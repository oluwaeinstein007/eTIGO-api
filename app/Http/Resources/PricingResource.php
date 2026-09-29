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
            'base_fare' => $this->base_fare,
            'per_km_rate' => $this->per_km_rate,
            'per_minute_rate' => $this->per_minute_rate,
            'minimum_fare' => $this->minimum_fare,
            'waiting_time_rate' => $this->waiting_time_rate,
            'version' => $this->version,
            'effective_from' => $this->effective_from,
            'city' => new CityResource($this->whenLoaded('city')),
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'created_by' => new UserResource($this->whenLoaded('createdByAdmin')),
            'created_at' => $this->created_at,
        ];
    }
}
