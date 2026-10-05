<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'colour' => $this->colour,
            'plate_number' => $this->plate_number,
            'year' => $this->year,
            'is_fleet' => $this->is_fleet,
            'vehicle_class_id' => $this->vehicle_class_id,
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'created_at' => $this->created_at,
        ];
    }
}
