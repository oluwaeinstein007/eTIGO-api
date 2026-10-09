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
            'driver_id' => $this->driver_id,
            'driver' => $this->when($this->relationLoaded('driver') && $this->driver, function () {
                return [
                    'id' => $this->driver->id,
                    'name' => $this->driver->user?->first_name.' '.$this->driver->user?->last_name,
                ];
            }),
            'make' => $this->make,
            'model' => $this->model,
            'colour' => $this->colour,
            'plate_number' => $this->plate_number,
            'year' => $this->year,
            'is_fleet' => $this->is_fleet,
            'is_assigned' => $this->when($this->is_fleet, $this->driver_id !== null),
            'vehicle_class_id' => $this->vehicle_class_id,
            'vehicle_class' => new VehicleClassResource($this->whenLoaded('vehicleClass')),
            'created_at' => $this->created_at,
        ];
    }
}
