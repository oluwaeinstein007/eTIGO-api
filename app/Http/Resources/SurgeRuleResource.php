<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SurgeRuleResource extends JsonResource
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
            'name' => $this->name,
            'type' => $this->type,
            'multiplier' => $this->multiplier,
            'conditions' => $this->conditions,
            'priority' => $this->priority,
            'is_active' => $this->is_active,
            'effective_from' => $this->effective_from,
            'effective_until' => $this->effective_until,
            'city' => new CityResource($this->whenLoaded('city')),
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'created_by' => new UserResource($this->whenLoaded('createdByAdmin')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
