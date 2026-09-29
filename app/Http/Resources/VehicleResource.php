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
            'vehicle_class' => $this->vehicle_class,
            'vehicle_class_approved' => $this->vehicle_class_approved,
            'created_at' => $this->created_at,
        ];
    }
}
