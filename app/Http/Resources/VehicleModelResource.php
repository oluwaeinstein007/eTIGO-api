<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_make_id' => $this->vehicle_make_id,
            'name' => $this->name,
            'fuel_type' => $this->fuel_type?->value ?? (is_string($this->fuel_type) ? $this->fuel_type : null),
            'fuel_type_label' => $this->fuel_type?->label(),
            'vehicle_class_id' => $this->vehicle_class_id,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
